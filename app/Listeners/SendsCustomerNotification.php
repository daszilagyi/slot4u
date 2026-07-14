<?php

namespace App\Listeners;

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\QuoteRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Notifications\Concerns\RecordsDelivery;
use App\Services\Notification\Notifier;
use Illuminate\Notifications\Notification;

/**
 * Shared plumbing for the lifecycle listeners that mail a booking's customer
 * (SLO-108/SLO-109): every one of them resolves the owning tenant and the
 * customer, skips what it cannot (or must not) mail, and sends through the
 * {@see Notifier} so the send is claimed in `notifications_log` and stays
 * idempotent under retries and repeated events.
 *
 * The listeners are synchronous — the queue work is the notification itself.
 */
abstract class SendsCustomerNotification
{
    public function __construct(protected readonly Notifier $notifier) {}

    /**
     * The tenant to mail on behalf of, or null when we must stay silent.
     *
     * Resolved with `withTrashed()` on purpose: {@see Tenant} is soft-deletable, so
     * the plain relation returns NULL for an archived tenant. That null would blow
     * up inside the notification — and because the listeners run synchronously
     * inside the status-change transaction, it would roll back the transition and
     * kill the tenant-less expiry commands (`bookings:expire-soft-holds`,
     * `waitlist:expire-offers`) for EVERY tenant, not just the archived one.
     *
     * A non-operational tenant (suspended/archived) is skipped rather than mailed:
     * its public surface is a 503/404 (EnsureTenantActive), so every link in the
     * mail would be dead.
     */
    protected function operationalTenant(Booking|QuoteRequest|WaitlistEntry $owner): ?Tenant
    {
        $tenant = $owner->tenant()->withTrashed()->first();

        if ($tenant === null || $tenant->trashed() || ! $tenant->status->isOperational()) {
            return null;
        }

        return $tenant;
    }

    /**
     * @param  RecordsDelivery&Notification  $notification
     */
    protected function sendToCustomer(
        Tenant $tenant,
        NotificationType $type,
        string $dedupeKey,
        ?User $customer,
        RecordsDelivery $notification,
    ): void {
        // A booking/quote/waitlist entry always has a customer, but never notify a
        // missing or email-less one (an admin-created walk-in may have neither).
        if ($customer === null || blank($customer->email)) {
            return;
        }

        $this->notifier->sendOnce(
            tenant: $tenant,
            type: $type,
            dedupeKey: $dedupeKey,
            recipient: $customer,
            recipientEmail: $customer->email,
            notification: $notification,
        );
    }
}
