<?php

namespace App\Enums;

/**
 * The delivery channels a notification template can target (docs/02). Email is the
 * MVP channel; SMS exists in the schema for when `feature_sms` lands.
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $channel) => $channel->value, self::cases());
    }
}
