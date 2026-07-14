<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the customer their booking request was turned down in the approval flow
 * (docs/04 §5, SLO-26/SLO-109). The rejection reason is mandatory on the admin
 * side, so it is the substance of the mail; the customer is pointed back at the
 * booking page to pick another slot.
 */
class BookingRejectedNotification extends TenantMailNotification
{
    public function __construct(private readonly Booking $booking, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.booking_rejected.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.booking_rejected.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.booking_rejected.intro', ['tenant' => $this->tenant->name]))
            ->line(__('app.mail.booking_rejected.code', ['code' => $this->booking->code]));

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.booking_rejected.service', ['service' => $this->booking->service->name]));
        }

        if ($this->booking->starts_at !== null) {
            $mail->line(__('app.mail.booking_rejected.when', [
                'when' => $this->tenantTime($this->booking->starts_at),
            ]));
        }

        if (filled($this->booking->reject_reason)) {
            $mail->line(__('app.mail.booking_rejected.reason', ['reason' => $this->booking->reject_reason]));
        }

        return $mail
            ->action(__('app.mail.booking_rejected.action'), $this->tenantUrl('/book'))
            ->line(__('app.mail.booking_rejected.outro'));
    }
}
