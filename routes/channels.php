<?php

use App\Broadcasting\TenantBookingChannel;
use Illuminate\Support\Facades\Broadcast;

/*
 * Broadcast channel authorization (SLO-117). The auth endpoint (/broadcasting/auth)
 * runs on the root domain, outside the tenant subdomain middleware, so the
 * authorization must not rely on an ambient tenant — see TenantBookingChannel.
 */

// Live admin booking feed, one private channel per tenant (staff-only, tenant-scoped).
Broadcast::channel('tenant.{tenantId}.bookings', TenantBookingChannel::class);
