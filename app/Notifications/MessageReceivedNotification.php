<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a customer the tenant replied in their message thread (SLO-36).
 *
 * ⚠️ The message text is deliberately NOT in the mail. The same product runs
 * psychologists and clinics, where the words themselves are health data, and a
 * mail is copied through relays and inboxes we do not control. The mail only
 * says there is something to read, and the button opens it behind the login.
 */
class MessageReceivedNotification extends TenantMailNotification
{
    public function __construct(Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    protected function templateType(): NotificationType
    {
        return NotificationType::MessageReceived;
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name,
            'tenant' => $this->tenant->name,
        ];
    }

    protected function templateAction(): array
    {
        return [
            __('app.mail.message_received.action'),
            $this->tenantUrl('/my/messages'),
        ];
    }

    protected function defaultMail(object $notifiable): MailMessage
    {
        [$actionLabel, $actionUrl] = $this->templateAction();

        return (new MailMessage)
            ->subject(__('app.mail.message_received.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.message_received.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.message_received.intro', ['tenant' => $this->tenant->name]))
            ->action($actionLabel, $actionUrl)
            ->line(__('app.mail.message_received.outro'));
    }
}
