<?php

declare(strict_types=1);

namespace App\Notifications\Platform;

use App\Services\Mail\MailTextStore;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "A Google / Facebook sign-in was linked to (or removed from) your account"
 * (SLO-252, docs/28 §5.2).
 *
 * A link is a way in that outlives the session that created it, so the owner is
 * told every time — the one signal that reaches them even when the session
 * doing it was not theirs.
 */
class SocialAccountChangedNotification extends Notification
{
    public function __construct(
        private readonly string $siteName,
        private readonly string $provider,
        private readonly bool $linked,
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
        $key = $this->linked ? 'social_account_linked' : 'social_account_unlinked';

        // The superadmin's wording if edited (SLO-246), else the lang default.
        return app(MailTextStore::class)->resolve($key, app()->getLocale())->applyTo(
            (new MailMessage)->greeting(__("app.mail.{$key}.greeting", ['name' => $notifiable->name])),
            ['name' => $notifiable->name, 'tenant' => $this->siteName, 'provider' => $this->provider],
            null,
        );
    }
}
