<?php

namespace App\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;

/**
 * Notifiable for an account-less guest (SLO-128): a booking or quote request made
 * by a visitor whose email could not become a customer of this tenant still has to
 * be confirmed by email.
 *
 * Laravel's own AnonymousNotifiable would route the mail but expose no `name`, and
 * every tenant mail template greets `$notifiable->name` — so this carries both.
 * It is deliberately NOT a User: nothing about a guest may be persisted as an
 * account, and `$notifiable->getKey()` must stay absent so the delivery listeners
 * never file the send against a real user.
 */
class GuestRecipient
{
    use Notifiable;

    public function __construct(
        public readonly string $email,
        public readonly string $name = '',
    ) {}

    /** The mail channel's address for this recipient. */
    public function routeNotificationForMail(): string
    {
        return $this->email;
    }

    /**
     * Laravel keys a notifiable by `getKey()` (the notification fake, the database
     * channel). A guest has no row, so their address is the identity — mirroring
     * how {@see AnonymousNotifiable} answers the same
     * call. Nothing persists it.
     */
    public function getKey(): string
    {
        return $this->email;
    }
}
