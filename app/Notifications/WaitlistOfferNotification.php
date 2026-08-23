<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Offers a waitlisted customer the seat that just freed up (docs/04 §3, SLO-25/109).
 * The offer is a head start, not a reservation — the seat is only theirs once they
 * book, and it passes to the next waiter when `offered_until` lapses — so the mail
 * leads with the deadline and links straight to the event's booking page.
 *
 * The link points at the public service page (the event list); a durable per-offer
 * URL is SLO-103.
 */
class WaitlistOfferNotification extends TenantMailNotification
{
    public function __construct(private readonly WaitlistEntry $entry, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    protected function templateType(): NotificationType
    {
        return NotificationType::WaitlistOffer;
    }

    protected function templateVars(object $notifiable): array
    {
        $event = $this->entry->event;

        return [
            'name' => $notifiable->name,
            'tenant' => $this->tenant->name,
            'service' => $event?->service?->name,
            'when' => $event?->starts_at !== null ? $this->tenantTime($event->starts_at) : null,
            'deadline' => $this->entry->offered_until !== null ? $this->tenantTime($this->entry->offered_until) : null,
        ];
    }

    protected function templateAction(): array
    {
        return [
            __('app.mail.waitlist_offer.action'),
            $this->bookingUrl(),
        ];
    }

    protected function defaultMail(object $notifiable): MailMessage
    {
        $event = $this->entry->event;

        $mail = (new MailMessage)
            ->subject(__('app.mail.waitlist_offer.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.waitlist_offer.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.waitlist_offer.intro', ['tenant' => $this->tenant->name]));

        if ($event?->service !== null) {
            $mail->line(__('app.mail.waitlist_offer.service', ['service' => $event->service->name]));
        }

        if ($event?->starts_at !== null) {
            $mail->line(__('app.mail.waitlist_offer.when', [
                'when' => $this->tenantTime($event->starts_at),
            ]));
        }

        if ($this->entry->offered_until !== null) {
            $mail->line(__('app.mail.waitlist_offer.deadline', [
                'deadline' => $this->tenantTime($this->entry->offered_until),
            ]));
        }

        [$actionLabel, $actionUrl] = $this->templateAction();

        return $mail
            ->action($actionLabel, $actionUrl)
            ->line(__('app.mail.waitlist_offer.outro'))
            ->line($this->closingLine());
    }

    /**
     * The public page listing the event's occurrences. The entry's own service_id is
     * the authority; an entry created against an event alone falls back to the
     * event's service.
     */
    private function bookingUrl(): string
    {
        $serviceId = $this->entry->service_id ?? $this->entry->event?->service_id;

        return $this->tenantUrl($serviceId === null ? '/book' : '/book?service='.$serviceId);
    }
}
