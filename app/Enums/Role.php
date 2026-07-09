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
     * Staff roles: the tenant members who operate the admin panel (docs/03).
     * The customer role is deliberately excluded — customers live in the
     * members area (SLO-33), not the admin panel; the EnsureUserIsStaff
     * middleware gates the panel on exactly these roles.
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
                Permission::CustomerEdit,
                Permission::ScheduleManage,
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
