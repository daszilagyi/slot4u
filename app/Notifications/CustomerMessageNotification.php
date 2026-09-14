<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\Tenant;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a staff member that a customer wrote (SLO-36). Staff-facing, so not a
 * tenant-editable template. Like the customer mail, it carries the customer's
 * name but not the message text ({@see MessageReceivedNotification}).
 */
class CustomerMessageNotification extends Notification implements ShouldQueue
{
    use Queueable, SuppressedForDemoTenant;

    public function __construct(
        private readonly Message $message,
        private readonly string $customerName,
        private readonly Tenant $tenant,
    ) {
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
        $mail = (new MailMessage)
            ->subject(__('app.mail.customer_message.subject', ['customer' => $this->customerName]))
            ->greeting(__('app.mail.customer_message.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.customer_message.intro', [
                'customer' => $this->customerName,
                'tenant' => $this->tenant->name,
            ]))
            ->action(__('app.mail.customer_message.action'), $this->threadUrl());

        return $this->suppressWhenDemo($mail, $this->tenant);
    }

    /**
     * The thread in the admin panel, on the tenant subdomain. Built from the
     * route, not a hand-written path: a hard-coded `/admin/billing` is exactly
     * how the commission mail ended up pointing at a 404 (SLO-240).
     */
    private function threadUrl(): string
    {
        return route('tenant.messages.show', [
            'tenant' => $this->tenant->slug,
            'customer' => $this->message->customer_id,
        ]);
    }
}
