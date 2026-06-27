<?php

namespace App\Enums;

/**
 * Tenant-scoped permission codes (docs/03 permission matrix). These are the
 * global spatie permission names (guard `web`); role→permission assignment per
 * tenant is seeded from {@see Role::permissions()}.
 *
 * The "own" scopes in the matrix (employee saját, customer önmaga) are enforced
 * at the policy layer once the owning models exist (M2/M3); the permission codes
 * here are the coarse grant.
 */
enum Permission: string
{
    case BookingView = 'booking.view';
    case BookingCreate = 'booking.create';
    case BookingEdit = 'booking.edit';
    case BookingCancel = 'booking.cancel';
    case BookingApprove = 'booking.approve';
    case CustomerView = 'customer.view';
    case CustomerEdit = 'customer.edit';
    case ServiceManage = 'service.manage';
    case StaffManage = 'staff.manage';
    case LocationManage = 'location.manage';
    case ScheduleManage = 'schedule.manage';
    case ReportView = 'report.view';
    case MessageSend = 'message.send';
    case TemplateManage = 'template.manage';
    case BillingView = 'billing.view';
    case BillingEdit = 'billing.edit';
    case SettingsEdit = 'settings.edit';
    case RoleManage = 'role.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
