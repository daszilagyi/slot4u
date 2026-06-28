<?php

namespace App\Actions\Fortify;

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Spatie\Permission\PermissionRegistrar;

/**
 * Self-service tenant registration (SLO-76): one company sign-up atomically
 * creates the tenant, its admin user and starts the 14-day trial (docs/03).
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /** Trial length in days (docs/03): trial → active on the free base plan. */
    public const TRIAL_DAYS = 14;

    /**
     * Validate and create a newly registered tenant + its admin user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
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
