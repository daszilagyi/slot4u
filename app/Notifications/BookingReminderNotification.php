<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Reminds the customer of a booking that is coming up within the day (docs/04,
 * SLO-110). Sent by the `bookings:remind` command, once per booking
 * (`booking:{id}:reminder_24h`). The time is stated in the tenant's timezone, and
 * the mail links to the public confirmation page where the booking can be looked
 * up (and, from the members area, moved or canceled).
 */
class BookingReminderNotification extends TenantMailNotification
{
    public function __construct(private readonly Booking $booking, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.reminder_24h.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.reminder_24h.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.reminder_24h.intro', ['tenant' => $this->tenant->name]));

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.reminder_24h.service', ['service' => $this->booking->service->name]));
        }

        // The command only picks bookings that have a start, but keep the mail
        // renderable without one rather than fataling on the queue.
        if ($this->booking->starts_at !== null) {
            $mail->line(__('app.mail.reminder_24h.when', [
                'when' => $this->tenantTime($this->booking->starts_at),
            ]));
        }

        return $mail
            ->line(__('app.mail.reminder_24h.code', ['code' => $this->booking->code]))
            ->action(
                __('app.mail.reminder_24h.action'),
                $this->tenantUrl('/booked/'.$this->booking->code),
            )
            ->line(__('app.mail.reminder_24h.outro'));
    }
}
