<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Notification\MessageTemplateRenderer;
use Database\Factories\MessageTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's override for one notification kind (docs/02, SLO-113). When an enabled
 * row exists for a (tenant, key, channel, locale), the outgoing mail renders its
 * subject + body from here instead of the built-in lang default; the placeholders
 * are substituted by {@see MessageTemplateRenderer}. Tenant-isolated via
 * BelongsToTenant.
 *
 * @property int $tenant_id
 * @property NotificationType $key
 * @property NotificationChannel $channel
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property bool $enabled
 */
class MessageTemplate extends Model
{
    /** @use HasFactory<MessageTemplateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'key',
        'channel',
        'locale',
        'subject',
        'body',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'key' => NotificationType::class,
            'channel' => NotificationChannel::class,
            'enabled' => 'boolean',
        ];
    }
}
