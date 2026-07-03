<?php

namespace App\Notifications;

use App\Models\Tenant;
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
    use Queueable;

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
        return (new MailMessage)
            ->subject(__('app.mail.staff_invitation.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.staff_invitation.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.staff_invitation.intro', ['tenant' => $this->tenant->name]))
            ->action(__('app.mail.staff_invitation.action'), $this->invitationUrl($notifiable))
            ->line(__('app.mail.staff_invitation.outro'));
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
