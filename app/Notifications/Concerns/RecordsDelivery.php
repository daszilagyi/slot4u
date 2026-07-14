<?php

namespace App\Notifications\Concerns;

/**
 * A notification whose delivery is tracked in `notifications_log`. It carries the
 * id of the pre-claimed log row so the NotificationSent / NotificationFailed
 * listeners can finalise that exact row's status (SLO-108).
 */
interface RecordsDelivery
{
    public function notificationLogId(): ?int;

    public function withNotificationLog(int $id): static;
}
