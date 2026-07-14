<?php

namespace App\Notifications\Concerns;

/**
 * Default {@see RecordsDelivery} implementation: holds the claimed
 * `notifications_log` row id on the notification instance so it survives
 * serialisation onto the queue and reaches the delivery listeners (SLO-108).
 */
trait TracksDelivery
{
    public ?int $notificationLogId = null;

    public function notificationLogId(): ?int
    {
        return $this->notificationLogId;
    }

    public function withNotificationLog(int $id): static
    {
        $this->notificationLogId = $id;

        return $this;
    }
}
