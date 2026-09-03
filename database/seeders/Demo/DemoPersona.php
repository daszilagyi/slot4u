<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Enums\Role;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Rbac\TenantRoleSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * One demo persona: a whole fictional business, seeded from nothing (SLO-183,
 * docs/20 §2).
 *
 * A subclass describes WHAT the business is — its services, staff, opening
 * hours and history. Everything that is the same for every persona lives here:
 * the tenant row and its `is_demo` flag, the role catalogue, the admin login,
 * and the deterministic toolkit handed to {@see build()}.
 *
 * The split matters because the guardrails are on this side. A persona cannot
 * accidentally create a tenant that is not marked demo, because it never
 * creates the tenant at all.
 */
abstract class DemoPersona
{
    /**
     * The password every demo login uses (docs/20 §2).
     *
     * ⚠️ Published in the spec on purpose — a sales demo people are invited to
     * try is not a secret. It is safe only because of what a demo tenant cannot
     * do (SLO-182: no real mail, payment or invoice) and because these accounts
     * live behind `is_demo`, never on a real tenant. Never reuse it anywhere
     * else.
     */
    public const PASSWORD = 'Slot4uDemo!2026';

    /** The subdomain the persona lives on — also its identity for `--tenant=`. */
    abstract public function slug(): string;

    /** The business's display name. */
    abstract public function name(): string;

    /**
     * Fill the tenant with this persona's world. Called inside the seed
     * transaction, with the tenant already created, marked demo and role-seeded.
     *
     * `$admin` is handed over rather than looked up: a persona routinely needs
     * it as more than a login — the solo practitioner IS the tenant admin and
     * her `staff.user_id` points at this row (docs/20 §2.1), and admin-side
     * transitions read better in the demo's history when they name an actor.
     */
    abstract protected function build(Tenant $tenant, User $admin, DemoDataFactory $data): void;

    /** The tenant's timezone; personas are Hungarian businesses unless they say otherwise. */
    public function timezone(): string
    {
        return 'Europe/Budapest';
    }

    public function locale(): string
    {
        return 'hu';
    }

    /**
     * The person behind the admin login.
     *
     * Defaults to a functional label, which is right for a business whose owner
     * the demo never names. A persona built around one practitioner overrides
     * it, because "Lélekút Pszichológiai Rendelő Admin" would be a stranger
     * signing the appointments her own calendar is made of.
     */
    public function adminName(): string
    {
        return $this->name().' Admin';
    }

    /**
     * The tenant's `settings` JSON — the public profile (description, contact,
     * address, opening hours) and any booking rule the persona wants to differ
     * from the platform default.
     *
     * Empty by default. It matters more than it looks: the public homepage
     * renders this and nothing else as the company profile, so a persona that
     * skips it is a business with no address and no phone number — the least
     * convincing thing a sales demo can be.
     *
     * @return array<string, mixed>
     */
    public function profileSettings(): array
    {
        return [];
    }

    /**
     * The admin's email. A non-deliverable domain by design (docs/20 §2): even
     * with the SLO-182 mail guardrail removed, there is no mailbox on the other
     * end of these addresses.
     */
    public function adminEmail(): string
    {
        return 'admin@'.$this->slug().'.demo.slot4u.hu';
    }

    /**
     * Create the tenant and hand it to {@see build()}.
     *
     * The tenant is `active` rather than `trial`: a trial expires, and a demo
     * that has expired is a demo that is broken on the morning nobody checked.
     * The billing exclusion from SLO-182 is what makes "active and never billed"
     * coherent.
     */
    final public function seed(): Tenant
    {
        $tenant = new Tenant([
            'name' => $this->name(),
            'slug' => $this->slug(),
            'status' => TenantStatus::Active,
            'timezone' => $this->timezone(),
            'locale' => $this->locale(),
            'settings' => $this->profileSettings(),
        ]);

        // `is_demo` is deliberately not fillable (SLO-182) — it lifts
        // restrictions, so it is set on a model in hand, never from an array.
        $tenant->is_demo = true;
        $tenant->save();

        // Seeders normally run with model events muted, which would skip
        // TenantObserver and leave the tenant with no roles — and therefore an
        // admin who can do nothing. Seeded explicitly, the way TenantDemoSeeder
        // does, so this works either way.
        app(TenantRoleSeeder::class)->seed($tenant);

        $admin = $this->createAdmin($tenant);

        $this->build($tenant, $admin, new DemoDataFactory($this->slug(), $this->timezone()));

        return $tenant;
    }

    /** The persona's tenant-admin login. */
    protected function createAdmin(Tenant $tenant): User
    {
        $admin = new User([
            'name' => $this->adminName(),
            'email' => $this->adminEmail(),
            'password' => Hash::make(self::PASSWORD),
        ]);
        $admin->tenant_id = $tenant->getKey();
        $admin->email_verified_at = now();
        $admin->save();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->getKey());

        try {
            $admin->syncRoles([Role::TenantAdmin->value]);
        } finally {
            // Leaving the team id set would silently scope every later
            // permission check in the process — including the next persona's.
            $registrar->setPermissionsTeamId(null);
        }

        return $admin;
    }
}
