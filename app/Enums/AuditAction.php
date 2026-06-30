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
}
