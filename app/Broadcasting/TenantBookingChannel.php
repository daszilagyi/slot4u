<?php

namespace App\Broadcasting;

use App\Models\User;

/**
 * Authorizes the live admin booking feed, `tenant.{tenantId}.bookings` (SLO-117).
 * Only a staff member of that same tenant may subscribe: the tenant_id must match
 * (isolation) and the user must be staff, not a customer. A cross-tenant or
 * customer subscription is refused.
 *
 * The broadcasting auth endpoint runs on the root domain, outside the tenant
 * subdomain middleware, so this must not rely on an ambient tenant — it authorizes
 * from the user's own tenant_id (User::isStaff() scopes its role check to the
 * user's own tenant team).
 */
class TenantBookingChannel
{
    public function join(User $user, string $tenantId): bool
    {
        return (int) $user->tenant_id === (int) $tenantId && $user->isStaff();
    }
}
