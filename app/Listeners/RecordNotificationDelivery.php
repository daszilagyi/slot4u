<?php

namespace App\Listeners;

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Notifications\Concerns\RecordsDelivery;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Carbon;

/**
 * Records the outcome of each delivery attempt on the `notifications_log` row of a
 * tracked notification: `sent` on a successful channel send, `failed` (with the
 * error) on a failed attempt. Laravel fires these events per attempt, so a
 * transient failure followed by a successful retry self-heals back to `sent` —
 * the row reflects the latest attempt, not a terminal verdict. Ignores
 * notifications that don't opt into tracking (e.g. the staff invitation), so it's
 * safe to bind globally (SLO-108).
 */
class RecordNotificationDelivery
{
    public function sent(NotificationSent $event): void
    {
        $this->finalise($event->notification, NotificationStatus::Sent, sentAt: Carbon::now(), error: null);
    }

    public function failed(NotificationFailed $event): void
    {
        $error = is_string($event->data['message'] ?? null) ? $event->data['message'] : null;

        $this->finalise($event->notification, NotificationStatus::Failed, sentAt: null, error: $error);
    }

    private function finalise(object $notification, NotificationStatus $status, ?Carbon $sentAt, ?string $error): void
    {
        if (! $notification instanceof RecordsDelivery) {
            return;
        }

        $logId = $notification->notificationLogId();

        if ($logId === null) {
            return;
        }

        // Update by primary key, outside the tenant scope: delivery events fire in
        // the queue worker where no tenant is bound.
        NotificationLog::withoutGlobalScopes()
            ->whereKey($logId)
            ->update([
                'status' => $status->value,
                'sent_at' => $sentAt,
                'error' => $error,
            ]);
    }
}
