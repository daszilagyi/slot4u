<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Confirms to the customer that their booking was canceled (docs/04 §5, SLO-109) —
 * whether they canceled it themselves or the tenant did. The cancellation reason is
 * included when one was recorded. A cancellation that is only the first half of a
 * reschedule never reaches this mail (see BookingCanceled::$rescheduled).
 */
class BookingCanceledNotification extends TenantMailNotification
{
    public function __construct(private readonly Booking $booking, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.booking_canceled.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.booking_canceled.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.booking_canceled.intro', ['tenant' => $this->tenant->name]))
            ->line(__('app.mail.booking_canceled.code', ['code' => $this->booking->code]));

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.booking_canceled.service', ['service' => $this->booking->service->name]));
        }

        if ($this->booking->starts_at !== null) {
            $mail->line(__('app.mail.booking_canceled.when', [
                'when' => $this->tenantTime($this->booking->starts_at),
            ]));
        }

        if (filled($this->booking->cancel_reason)) {
            $mail->line(__('app.mail.booking_canceled.reason', ['reason' => $this->booking->cancel_reason]));
        }

        return $mail
            ->action(__('app.mail.booking_canceled.action'), $this->tenantUrl('/book'))
            ->line(__('app.mail.booking_canceled.outro'));
    }
}
