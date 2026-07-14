<?php

namespace App\Services\Notification;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Notifications\Concerns\RecordsDelivery;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\Notification;

/**
 * Single entry point for sending a tracked, idempotent notification (SLO-108).
 *
 * `sendOnce()` claims a `notifications_log` row keyed by (tenant, type,
 * dedupe_key) before dispatching: if the row already exists the notification was
 * already sent (or is in flight), so it is skipped. This is what guarantees a
 * booking is confirmed once and a 24h reminder goes out exactly once, even across
 * retries and repeated scheduled runs. The delivery outcome (sent/failed) of each
 * attempt is recorded by the NotificationSent / NotificationFailed listeners.
 */
class Notifier
{
    public function __construct(private readonly Dispatcher $notifications) {}

    /**
     * Claim-and-send: dispatch the notification to the recipient only if this
     * (tenant, type, dedupe_key) has not been claimed before. Runs outside the
     * tenant global scope so scheduled jobs (no bound tenant) stay correct; the
     * tenant is always pinned explicitly. Returns the claimed log row, or null
     * when the send was skipped as a duplicate.
     *
     * @param  RecordsDelivery&Notification  $notification
     */
    public function sendOnce(
        Tenant $tenant,
        NotificationType $type,
        string $dedupeKey,
        object $recipient,
        string $recipientEmail,
        RecordsDelivery $notification,
    ): ?NotificationLog {
        $log = NotificationLog::withoutGlobalScopes()->createOrFirst(
            [
                'tenant_id' => $tenant->getKey(),
                'type' => $type->value,
                'dedupe_key' => $dedupeKey,
            ],
            [
                'channel' => 'email',
                'recipient' => $recipientEmail,
                'status' => NotificationStatus::Pending->value,
            ],
        );

        // Already claimed by an earlier send → idempotent no-op.
        if (! $log->wasRecentlyCreated) {
            return null;
        }

        $this->notifications->send($recipient, $notification->withNotificationLog($log->getKey()));

        return $log;
    }
}
