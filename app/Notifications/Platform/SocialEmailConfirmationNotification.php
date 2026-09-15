<?php

declare(strict_types=1);

namespace App\Notifications\Platform;

use App\Services\Mail\MailTextStore;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Confirm this is your address" — sent when a person signed in with a
 * Facebook account that has no e-mail and typed one in (SLO-252, docs/28).
 *
 * Sent to an on-demand route (the address), not to a user: there may be no
 * account for it yet, and whether there is must not change what arrives.
 */
class SocialEmailConfirmationNotification extends Notification
{
    public function __construct(
        private readonly string $url,
        private readonly string $name,
        private readonly string $siteName,
        private readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The superadmin's wording if edited (SLO-246), else the lang default.
        return app(MailTextStore::class)->resolve('social_email_confirmation', app()->getLocale())->applyTo(
            (new MailMessage)->greeting(__('app.mail.social_email_confirmation.greeting', ['name' => $this->name])),
            ['name' => $this->name, 'tenant' => $this->siteName, 'count' => $this->minutes],
            [__('app.mail.social_email_confirmation.action'), $this->url],
        );
    }
}
