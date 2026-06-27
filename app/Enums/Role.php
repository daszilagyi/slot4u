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
            self::Customer => [
                Permission::BookingView,
                Permission::BookingCreate,
                Permission::BookingEdit,
                Permission::BookingCancel,
                Permission::CustomerView,
                Permission::CustomerEdit,
                Permission::MessageSend,
            ],
        };
    }
}
