<?php

namespace App\Services\Notification;

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\QuoteRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\Concerns\RecordsDelivery;
use Illuminate\Notifications\Notification;

/**
 * The policy layer over {@see Notifier}: who we may mail on a tenant's behalf, and
 * whom (SLO-109/SLO-110). Shared by the event listeners and the scheduled commands
 * so both answer those two questions the same way.
 */
class CustomerNotifier
{
    /**
     * Tenants already resolved by this instance, keyed by id (null = must not mail).
     * The scheduled commands walk many bookings of the same few tenants — without
     * this they would re-query the tenant for every single row.
     *
     * @var array<int, Tenant|null>
     */
    private array $tenants = [];

    public function __construct(private readonly Notifier $notifier) {}

    /**
     * The tenant to mail on behalf of, or null when we must stay silent.
     *
     * Resolved with `withTrashed()` on purpose: {@see Tenant} is soft-deletable, so
     * the plain relation returns NULL for an archived tenant. The event listeners run
     * synchronously inside the status-change transaction, so that null would blow up
     * mid-transaction, roll back the transition, and kill the tenant-less scheduled
     * commands for EVERY tenant — not just the archived one.
     *
     * A non-operational tenant (suspended/archived) is skipped rather than mailed:
     * its public surface answers 503/404 (EnsureTenantActive), so every link in the
     * mail would be dead.
     */
    public function operationalTenant(Booking|QuoteRequest|WaitlistEntry $owner): ?Tenant
    {
        $tenantId = (int) $owner->tenant_id;

        if (! array_key_exists($tenantId, $this->tenants)) {
            $this->tenants[$tenantId] = $this->resolveOperationalTenant($owner);
        }

        return $this->tenants[$tenantId];
    }

    private function resolveOperationalTenant(Booking|QuoteRequest|WaitlistEntry $owner): ?Tenant
    {
        $tenant = $owner->tenant()->withTrashed()->first();

        if ($tenant === null || $tenant->trashed() || ! $tenant->status->isOperational()) {
            return null;
        }

        return $tenant;
    }

    /**
     * Send once, to a customer who can actually receive it. Returns the claimed log
     * row, or null when nothing was sent (no customer, no address, or the send was
     * already claimed — see {@see Notifier::sendOnce()}).
     *
     * @param  RecordsDelivery&Notification  $notification
     */
    public function sendToCustomer(
        Tenant $tenant,
        NotificationType $type,
        string $dedupeKey,
        ?User $customer,
        RecordsDelivery $notification,
    ): ?NotificationLog {
        // A booking/quote/waitlist entry always has a customer, but never notify a
        // missing or email-less one (an admin-created walk-in may have neither).
        if ($customer === null || blank($customer->email)) {
            return null;
        }

        // Defence in depth: this is the single gate every customer mail passes
        // through, so make a cross-tenant recipient structurally impossible rather
        // than relying on every caller's query being tenant-scoped.
        if ((int) $customer->tenant_id !== (int) $tenant->getKey()) {
            return null;
        }

        return $this->notifier->sendOnce(
            tenant: $tenant,
            type: $type,
            dedupeKey: $dedupeKey,
            recipient: $customer,
            recipientEmail: $customer->email,
            notification: $notification,
        );
    }
}
