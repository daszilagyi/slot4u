<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use App\Services\Mail\MailTextStore;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails an invited staff member a link to set their password and enter the
 * tenant (SLO-17). The link is a password-reset token pointed at the tenant
 * subdomain, so the employee arrives in their own tenant's space.
 */
class StaffInvitationNotification extends Notification
{
    use Queueable, SuppressedForDemoTenant;

    public function __construct(
        private readonly Tenant $tenant,
        private readonly string $token,
    ) {
        // Render the mail in the tenant's locale regardless of queue context.
        $this->locale = $tenant->locale;
    }

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
        $mail = app(MailTextStore::class)->resolve('staff_invitation', $this->tenant->locale)->applyTo(
            new MailMessage,
            ['name' => $notifiable->name, 'tenant' => $this->tenant->name],
            $this->invitationUrl($notifiable),
        );

        return $this->suppressWhenDemo($mail, $this->tenant);
    }

    /**
     * The set-password URL on the tenant subdomain (password.reset route).
     */
    private function invitationUrl(object $notifiable): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $this->tenant->slug.'.'.config('tenancy.central_domain');

        return sprintf(
            '%s://%s/reset-password/%s?%s',
            $scheme,
            $host,
            $this->token,
            http_build_query(['email' => $notifiable->email]),
        );
    }
}
