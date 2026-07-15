<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the customer their booking was moved (docs/04 §2, SLO-109). A reschedule is
 * a cancel + create pair under the hood, so this single mail stands in for both
 * halves: it names the old slot it replaces and the new one that now holds. It
 * carries the NEW booking's code — the original's confirmation link is dead.
 */
class BookingRescheduledNotification extends TenantMailNotification
{
    public function __construct(
        private readonly Booking $booking,
        private readonly Booking $original,
        Tenant $tenant,
    ) {
        $this->renderForTenant($tenant);
    }

    protected function templateType(): NotificationType
    {
        return NotificationType::BookingModified;
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name,
            'tenant' => $this->tenant->name,
            'previous' => $this->original->starts_at !== null ? $this->tenantTime($this->original->starts_at) : null,
            'when' => $this->booking->starts_at !== null ? $this->tenantTime($this->booking->starts_at) : null,
            'service' => $this->booking->service?->name,
            'code' => $this->booking->code,
        ];
    }

    protected function templateAction(): array
    {
        return [
            __('app.mail.booking_modified.action'),
            $this->tenantUrl('/booked/'.$this->booking->code),
        ];
    }

    protected function defaultMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.booking_modified.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.booking_modified.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.booking_modified.intro', ['tenant' => $this->tenant->name]));

        if ($this->original->starts_at !== null) {
            $mail->line(__('app.mail.booking_modified.previous', [
                'when' => $this->tenantTime($this->original->starts_at),
            ]));
        }

        if ($this->booking->starts_at !== null) {
            $mail->line(__('app.mail.booking_modified.when', [
                'when' => $this->tenantTime($this->booking->starts_at),
            ]));
        }

        if ($this->booking->service !== null) {
            $mail->line(__('app.mail.booking_modified.service', ['service' => $this->booking->service->name]));
        }

        [$actionLabel, $actionUrl] = $this->templateAction();

        return $mail
            ->line(__('app.mail.booking_modified.code', ['code' => $this->booking->code]))
            ->action($actionLabel, $actionUrl)
            ->line(__('app.mail.booking_modified.outro'));
    }
}
