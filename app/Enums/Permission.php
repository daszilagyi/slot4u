<?php

namespace App\Enums;

use App\Support\CustomerVisibility;
use App\Support\ScheduleVisibility;

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
    /**
     * Widens `customer.view` from "saját ügyfelei" to the whole roster
     * ({@see CustomerVisibility}). Before SLO-142 this distinction
     * was hardcoded to the tenant-admin and manager role NAMES, which a custom
     * role could never satisfy — a code makes it configurable like everything
     * else in the matrix. Without `customer.view` it grants nothing.
     */
    case CustomerViewAll = 'customer.view_all';
    case CustomerEdit = 'customer.edit';
    case ServiceManage = 'service.manage';
    case StaffManage = 'staff.manage';
    case LocationManage = 'location.manage';
    case ScheduleManage = 'schedule.manage';
    /**
     * Widens `schedule.manage` from the holder's own linked staff to every
     * resource of the tenant ({@see ScheduleVisibility}). Without it the grant
     * is the matrix's employee "saját" cell: an employee may keep their own
     * working hours, and only their own — before SLO-177 the code was
     * all-or-nothing and the employee role held it in full, so anyone could
     * rewrite a colleague's or a room's schedule. Rooms have no ownership axis
     * at all, so they are visible only with this code. Without `schedule.manage`
     * it grants nothing.
     */
    case ScheduleManageAll = 'schedule.manage_all';
    case ReportView = 'report.view';
    case MessageSend = 'message.send';
    case TemplateManage = 'template.manage';
    case BillingView = 'billing.view';
    case BillingEdit = 'billing.edit';
    case SettingsEdit = 'settings.edit';
    case RoleManage = 'role.manage';
    /**
     * Handle data-subject requests: see the erasure queue and execute or refuse
     * a request (SLO-159). Deliberately NOT admin-reserved — GDPR compliance is
     * exactly the kind of duty a tenant delegates to one named person, and
     * forcing it into the tenant-admin role would push that person into a role
     * that also controls billing. It is simply not seeded to any other role, so
     * the tenant has to grant it on purpose.
     */
    case PrivacyManage = 'privacy.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }

    /**
     * Where this code sits in the tenant's role editor (SLO-141).
     */
    public function group(): PermissionGroup
    {
        return match ($this) {
            self::BookingView, self::BookingCreate, self::BookingEdit,
            self::BookingCancel, self::BookingApprove => PermissionGroup::Bookings,
            self::CustomerView, self::CustomerViewAll, self::CustomerEdit => PermissionGroup::Customers,
            self::ServiceManage, self::StaffManage, self::LocationManage => PermissionGroup::Catalog,
            self::ScheduleManage, self::ScheduleManageAll => PermissionGroup::Schedule,
            self::ReportView => PermissionGroup::Insights,
            self::MessageSend, self::TemplateManage => PermissionGroup::Communication,
            self::BillingView, self::BillingEdit,
            self::SettingsEdit, self::RoleManage, self::PrivacyManage => PermissionGroup::Administration,
        };
    }

    /**
     * The tenant feature this code is meaningless without — granting it while the
     * feature is off would hand out a permission whose every route answers 403
     * anyway (the middleware chain is feature-gate *then* `can:`, docs/03).
     *
     * Only the codes whose whole surface sits behind one flag are listed:
     * `booking.create`/`booking.edit` also gate quote-request routes, but they
     * gate plain bookings too, so they are never feature-dependent as a whole.
     * Null = always offerable.
     */
    public function requiredFeature(): ?Feature
    {
        return match ($this) {
            self::ReportView => Feature::Reports,
            self::BookingApprove => Feature::ApprovalFlow,
            self::MessageSend => Feature::Messages,
            default => null,
        };
    }

    /**
     * Codes the tenant admin keeps to itself — never grantable to another role or
     * user, whatever the editor asks for (SLO-141).
     *
     * `billing.*` because the commission invoices are the tenant admin's own
     * financial standing with slot4u (docs/03 matrix, and the issue's explicit
     * guardrail). `role.manage` because a role that may edit roles can grant
     * itself everything in one request — leaving it grantable would make every
     * other guardrail here a formality.
     */
    public function isAdminReserved(): bool
    {
        return match ($this) {
            self::BillingView, self::BillingEdit, self::RoleManage => true,
            default => false,
        };
    }
}
