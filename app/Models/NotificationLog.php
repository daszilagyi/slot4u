<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Notification\Notifier;
use Database\Factories\NotificationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ledger row for one outgoing notification (docs/02, SLO-108). Tenant-isolated
 * via BelongsToTenant. The (tenant_id, type, dedupe_key) unique index makes a
 * send idempotent — {@see Notifier::sendOnce()} claims
 * a row before dispatching, and the NotificationSent/NotificationFailed listeners
 * finalise its status.
 *
 * @property int $tenant_id
 * @property NotificationType $type
 * @property string $channel
 * @property string $recipient
 * @property NotificationStatus $status
 * @property string|null $dedupe_key
 * @property Carbon|null $sent_at
 * @property string|null $error
 */
class NotificationLog extends Model
{
    /** @use HasFactory<NotificationLogFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'notifications_log';

    protected $fillable = [
        'tenant_id',
        'type',
        'channel',
        'recipient',
        'status',
        'dedupe_key',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }
}
