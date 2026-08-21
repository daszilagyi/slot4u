<?php

namespace App\Actions\Fortify;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Legal\RecordConsent;
use App\Enums\ConsentContext;
use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\Phone;
use App\Services\Legal\LegalDocumentRegistry;
use App\Support\PhoneNumber;
use App\Tenancy\TenantHostResolver;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
        private readonly LegalDocumentRegistry $legal,
        private readonly RecordConsent $recordConsent,
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
     * @param  array<string, mixed>  $input  raw registration input; `phone` is
     *                                       rewritten to E.164 (or null) below
     */
    private function registerCustomer(array $input, Tenant $tenant): User
    {
        // The dialling region is read off the tenant we resolved from the host,
        // not from the container: this route runs outside `identify.tenant`, so
        // nothing is bound yet (the set() below is what binds it).
        $region = PhoneNumber::regionFor($tenant);
        $phone = $input['phone'] ?? null;
        // A non-string is left alone for the `string` rule to reject.
        $input['phone'] = is_string($phone) ? PhoneNumber::normalizeInput($phone, $region) : $phone;

        $documents = $this->legal->currentForTenant($tenant);

        $this->validateWithLegal($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => Phone::rules($region),
            'password' => $this->passwordRules(),
        ], $tenant, $documents);

        $this->tenants->set($tenant);

        $user = ($this->createCustomer)([
            'name' => $input['name'],
            'email' => $input['email'],
            'phone' => $input['phone'] ?? null,
            'password' => $input['password'],
        ]);

        // The customer accepts the TENANT's documents, not the platform's: the
        // tenant is the controller of their data (docs/19 §1), and slot4u is not
        // a party to that relationship at all.
        $this->recordConsent->many(
            $documents, $tenant, ConsentContext::CustomerRegistration,
            user: $user, ipAddress: $this->request->ip(),
        );

        return $user;
    }

    /**
     * Register a new tenant + its admin user (SLO-76).
     *
     * @param  array<string, string>  $input
     */
    private function createTenantWithAdmin(array $input): User
    {
        $documents = $this->legal->currentPlatform();

        $this->validateWithLegal($input, [
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
        ], null, $documents, [
            'slug.not_in' => __('validation.custom.slug.reserved'),
        ]);

        return DB::transaction(function () use ($input, $documents): User {
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

            // Inside the transaction on purpose: a tenant that exists without the
            // acceptance that created it is a compliance gap that nothing later
            // would notice. The consent belongs to the new tenant even though the
            // document is the platform's — it is that tenant's own evidence.
            $this->recordConsent->many(
                $documents, $tenant, ConsentContext::TenantRegistration,
                user: $user, ipAddress: $this->request->ip(),
            );

            return $user;
        });
    }

    /**
     * Validate registration input together with the acceptance a scope requires.
     *
     * The acceptance rules disappear when the scope has published nothing: a
     * tenant that has not written a privacy notice yet must not have its sign-up
     * page refuse every visitor over a setting it does not know exists.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @param  Collection<string, LegalDocument>  $documents
     * @param  array<string, string>  $messages
     */
    private function validateWithLegal(array $input, array $rules, ?Tenant $tenant, $documents, array $messages = []): void
    {
        if ($documents->isNotEmpty()) {
            $rules['accepted_legal'] = ['required', 'accepted'];
            $rules['legal_document_ids'] = ['required', 'array'];
            $rules['legal_document_ids.*'] = ['integer'];
        }

        $validator = Validator::make($input, $rules, $messages);

        $validator->after(function ($validator) use ($input, $tenant, $documents): void {
            // A version published between rendering the form and submitting it
            // must not be recorded as accepted — the person never saw it. Nor may
            // the superseded one be recorded. The submission is refused instead.
            if ($documents->isEmpty()) {
                return;
            }

            if (! $this->legal->isCurrentSet($tenant, (array) ($input['legal_document_ids'] ?? []))) {
                $validator->errors()->add('accepted_legal', __('app.legal.stale'));
            }
        });

        $validator->validate();
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
