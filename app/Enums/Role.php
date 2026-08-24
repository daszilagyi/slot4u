<?php

namespace App\Enums;

/**
 * Roles (docs/03 role hierarchy). The four tenant roles are seeded per tenant
 * team (team = tenant_id); SuperAdmin is global (tenant_id = null) and bypasses
 * permission checks via a Gate::before hook rather than holding every permission.
 */
enum Role: string
{
    case TenantAdmin = 'tenant-admin';
    case Manager = 'manager';
    case Employee = 'employee';
    case Customer = 'customer';
    case SuperAdmin = 'super-admin';

    /**
     * Roles seeded inside each tenant team.
     *
     * @return list<self>
     */
    public static function tenantRoles(): array
    {
        return [self::TenantAdmin, self::Manager, self::Employee, self::Customer];
    }

    public function isTenantRole(): bool
    {
        return $this !== self::SuperAdmin;
    }

    /**
     * The role names slot4u itself defines. A tenant may add its own roles
     * (SLO-142) but may never create, rename or delete one of these: the code
     * checks them by name (seeding, the members-area wall, the role editor's
     * guardrails), so a tenant-supplied row wearing a built-in name would be
     * indistinguishable from the real thing.
     *
     * @return list<string>
     */
    public static function builtInNames(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    public static function isBuiltIn(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }

    /**
     * Staff roles: the tenant members who operate the admin panel (docs/03).
     *
     * ⚠️ This is the SEEDED default set, not the definition of "staff". Since
     * SLO-142 a tenant may add its own roles, and a user holding only a custom
     * role would fail a name-based membership test and be locked out of the
     * panel with a 403. Staff is therefore defined by exclusion — any tenant
     * role except `customer` ({@see isStaffRoleName()}) — and this list is only
     * used where the four seeded roles are genuinely meant.
     *
     * @return list<self>
     */
    public static function staffRoles(): array
    {
        return [self::TenantAdmin, self::Manager, self::Employee];
    }

    /**
     * The `web`-guard role names for {@see staffRoles()}.
     *
     * @return list<string>
     */
    public static function staffRoleNames(): array
    {
        return array_map(fn (self $role) => $role->value, self::staffRoles());
    }

    /**
     * Whether holding this role name makes a user staff, i.e. an operator of the
     * admin panel rather than a members-area customer (SLO-86, SLO-142).
     *
     * Everything except `customer` counts, custom tenant roles included: a role
     * a tenant creates in the admin panel's own role editor exists precisely to
     * be used in the admin panel. `customer` is the one role that means the
     * opposite, and `super-admin` never reaches here (super-admins hold no
     * tenant role and short-circuit earlier).
     */
    public static function isStaffRoleName(string $name): bool
    {
        return $name !== self::Customer->value;
    }

    /**
     * The default permission grant for this role (docs/03 matrix). Tenant admins
     * receive every permission; the super-admin grant is empty because the
     * Gate::before hook short-circuits its checks.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],
            self::TenantAdmin => Permission::cases(),
            self::Manager => [
                Permission::BookingView,
                Permission::BookingCreate,
                Permission::BookingEdit,
                Permission::BookingCancel,
                Permission::BookingApprove,
                Permission::CustomerView,
                Permission::CustomerViewAll,
                Permission::CustomerEdit,
                Permission::ScheduleManage,
                Permission::ScheduleManageAll,
                Permission::ReportView,
                Permission::MessageSend,
            ],
            self::Employee => [
                Permission::BookingView,
                Permission::BookingCreate,
                Permission::BookingEdit,
                Permission::BookingCancel,
                Permission::CustomerView,
                Permission::CustomerEdit,
                Permission::ScheduleManage,
                Permission::MessageSend,
            ],
            // The customer role holds NO admin permission codes (SLO-86): those
            // codes gate the tenant admin panel, which is staff-only. A
            // customer's "own/self" scope from the docs/03 matrix (own bookings,
            // own profile) is enforced by ownership policies in the members area
            // (SLO-33), not by these coarse grants; public-flow booking creation
            // is unauthenticated and needs no permission.
            self::Customer => [],
        };
    }
}
