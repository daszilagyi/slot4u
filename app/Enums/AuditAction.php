<?php

namespace App\Enums;

/**
 * Semantic audit-trail action codes (SLO-78). Stored as the `action` column on
 * `audit_logs`; the frontend maps each value to a label via the `audit_action.*`
 * lang keys. Backed enum so callers are typo-safe and the filter endpoint can
 * validate with Rule::enum.
 */
enum AuditAction: string
{
    case TenantSuspended = 'tenant.suspended';
    case TenantActivated = 'tenant.activated';
    case TenantArchived = 'tenant.archived';
    case TenantStatusChanged = 'tenant.status_changed';
    case TenantTrialExtended = 'tenant.trial_extended';
    case TenantFeatureToggled = 'tenant.feature_toggled';
    case TenantUpdated = 'tenant.updated';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationStopped = 'impersonation.stopped';

    // Commission configuration (J8a, docs/10 §10). Pricing changes money, so
    // every version and per-tenant override lands in the trail.
    case CommissionSettingsCreated = 'commission.settings_created';
    case CommissionOverrideUpdated = 'commission.override_updated';
    case CommissionOverrideCleared = 'commission.override_cleared';

    // Commission invoice management (J8b, docs/10 §10). Settling, voiding and
    // re-sending a slot4u→tenant invoice all move real money / suspension state,
    // so each superadmin action is recorded.
    case CommissionInvoicePaid = 'commission.invoice_paid';
    case CommissionInvoiceVoided = 'commission.invoice_voided';
    case CommissionInvoiceResent = 'commission.invoice_resent';

    // Booking list price edited by an admin (SLO-126, docs/10 §3.3). The price
    // is the commission base, so changing it moves what the tenant owes —
    // recorded with the old and new amount.
    case BookingPriceChanged = 'booking.price_changed';

    // Tenant role customization (SLO-141, docs/03). Who may do what inside a
    // tenant is a security boundary, so every change to a role's grant — and
    // every reset back to the seeded default — lands in the trail with the old
    // and new permission list.
    case RolePermissionsUpdated = 'role.permissions_updated';
    case RolePermissionsReset = 'role.permissions_reset';

    // Custom tenant roles and per-user overrides (SLO-142, docs/03). Same
    // reasoning as above: creating, renaming or deleting a role and moving a
    // single user's grant all redraw the security boundary, so each lands in the
    // trail with what it replaced.
    case RoleCreated = 'role.created';
    case RoleRenamed = 'role.renamed';
    case RoleDeleted = 'role.deleted';
    case UserRbacUpdated = 'user.rbac_updated';
}
