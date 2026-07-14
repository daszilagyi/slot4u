<?php

namespace App\Enums;

/**
 * Delivery status of a `notifications_log` row: claimed but not yet delivered,
 * successfully sent, or failed after the queue exhausted its retries (SLO-108).
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
