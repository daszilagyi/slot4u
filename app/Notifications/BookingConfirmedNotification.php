<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Confirms a booking to the customer once it reaches `confirmed` (SLO-108). The
 * mail links to the public confirmation page (`/booked/{code}`), whose code is the
 * customer's access key. A booking that replaces a rescheduled one gets
 * {@see BookingRescheduledNotification} instead.
 */
class BookingConfirmedNotification extends TenantMailNotification
{
    public function __construct(private readonly Booking $booking, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.booking_confirmed.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.booking_confirmed.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.booking_confirmed.intro', ['tenant' => $this->tenant->name]))
            ->line(__('app.mail.booking_confirmed.code', ['code' => $this->booking->code]));

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.booking_confirmed.service', ['service' => $this->booking->service->name]));
        }

        if ($this->booking->starts_at !== null) {
            $mail->line(__('app.mail.booking_confirmed.when', [
                'when' => $this->tenantTime($this->booking->starts_at),
            ]));
        }

        return $mail
            ->action(
                __('app.mail.booking_confirmed.action'),
                $this->tenantUrl('/booked/'.$this->booking->code),
            )
            ->line(__('app.mail.booking_confirmed.outro'));
    }
}
