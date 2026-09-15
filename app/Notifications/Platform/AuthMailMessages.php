<?php

namespace App\Notifications\Platform;

use App\Services\Mail\MailTextStore;
use App\Support\Mail\MailText;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The two mails the framework sends on its own — email verification after
 * registration and the forgotten-password link — built from lang keys (SLO-244).
 * Until this they went out with Laravel's English defaults: "Verify Email
 * Address", "Hello!", "Regards". A new tenant's very first letter from slot4u
 * was the one mail that was not in Hungarian.
 *
 * Only the text changes. The link is still the framework's: the signed
 * verification URL, and the reset URL on the host the request came from.
 */
final class AuthMailMessages
{
    public static function register(): void
    {
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url): MailMessage => self::verifyEmail($notifiable, $url));

        ResetPassword::toMailUsing(fn (object $notifiable, string $token): MailMessage => self::resetPassword($notifiable, $token));
    }

    public static function verifyEmail(object $notifiable, string $url): MailMessage
    {
        return self::text('verify_email')->applyTo(
            new MailMessage,
            ['name' => $notifiable->name, 'count' => config('auth.verification.expire', 60)],
            $url,
        );
    }

    public static function resetPassword(object $notifiable, string $token): MailMessage
    {
        // The framework's own URL, unchanged — see ResetPassword::resetUrl().
        $url = url(route('password.reset', [
            'token' => $token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return self::text('reset_password')->applyTo(
            new MailMessage,
            ['name' => $notifiable->name, 'count' => $expire],
            $url,
        );
    }

    /** The superadmin's wording if they edited it (SLO-246), else the lang default. */
    private static function text(string $key): MailText
    {
        return app(MailTextStore::class)->resolve($key, app()->getLocale());
    }
}
