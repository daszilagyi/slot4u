<?php

namespace App\Actions\Fortify;

use App\Actions\Customer\CreateCustomer;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantHostResolver;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\PermissionRegistrar;

/**
 * Host-aware self-service registration. On the central domain it is tenant
 * sign-up (SLO-76): one company sign-up atomically creates the tenant, its admin
 * user and starts the 14-day trial (docs/03). On a `{slug}.{central}` subdomain
 * it is customer sign-up for that tenant (SLO-95) — the Fortify `/register` route
 * is domain-less, so the tenant is resolved from the request host.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** Trial length in days (docs/03): trial → active on the free base plan. */
    public const TRIAL_DAYS = 14;

    public function __construct(
        private readonly TenantHostResolver $hostResolver,
        private readonly TenantManager $tenants,
        private readonly Request $request,
        private readonly CreateCustomer $createCustomer,
    ) {}

    /**
     * Validate and create the newly registered user: a customer on a tenant
     * subdomain, otherwise a new tenant + its admin.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $tenant = $this->hostResolver->resolve($this->request->getHost());

        if ($tenant !== null) {
            // The Fortify route runs outside ensure.tenant.active, so a suspended
            // tenant must be refused here — otherwise a customer row is created
            // for a tenant that can't be used (the redirect would 503 anyway).
            abort_unless($tenant->status->isOperational(), 503);

            return $this->registerCustomer($input, $tenant);
        }

        return $this->createTenantWithAdmin($input);
    }

    /**
     * Register a customer for an existing tenant (SLO-95). Reuses CreateCustomer
     * with the customer's own password; the tenant context is bound explicitly
     * because the Fortify route runs outside `identify.tenant`.
     *
     * @param  array<string, string>  $input
     */
    private function registerCustomer(array $input, Tenant $tenant): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => $this->passwordRules(),
        ])->validate();

        $this->tenants->set($tenant);

        return ($this->createCustomer)([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'password' => $input['password'],
        ]);
    }

    /**
     * Register a new tenant + its admin user (SLO-76).
     *
     * @param  array<string, string>  $input
     */
    private function createTenantWithAdmin(array $input): User
    {
        Validator::make($input, [
            'company_name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'lowercase', 'min:3', 'max:63',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique(Tenant::class, 'slug'),
                Rule::notIn($this->reservedSlugs()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => $this->passwordRules(),
        ], [
            'slug.not_in' => __('validation.custom.slug.reserved'),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            // Creating the tenant fires TenantObserver → seeds the tenant roles.
            $tenant = Tenant::create([
                'name' => $input['company_name'],
                'slug' => $input['slug'],
                'status' => TenantStatus::Trial,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
                'timezone' => 'Europe/Budapest',
                'locale' => 'hu',
            ]);

            // tenant_id is set from the freshly created tenant — never from the
            // untrusted registration input (a null tenant_id would mint a
            // super-admin via the isSuperAdmin() invariant).
            $user = User::create([
                'tenant_id' => $tenant->getKey(),
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);

            $this->assignTenantAdmin($user, $tenant);

            return $user;
        });
    }

    /**
     * Grant the registering user the tenant-admin role within the tenant's team.
     */
    private function assignTenantAdmin(User $user, Tenant $tenant): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $user->assignRole(Role::TenantAdmin->value);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * Subdomain labels that may never be a tenant slug.
     *
     * @return list<string>
     */
    private function reservedSlugs(): array
    {
        return array_merge(
            (array) config('tenancy.reserved_subdomains', []),
            [config('tenancy.admin_subdomain')],
        );
    }
}
