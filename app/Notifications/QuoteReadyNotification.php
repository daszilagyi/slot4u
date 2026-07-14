<?php

namespace App\Notifications;

use App\Models\QuoteRequest;
use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells the customer their quote is ready (docs/04 §6, SLO-27/SLO-109), i.e. the
 * request reached `quoted`. The price and its validity are in the mail body so the
 * customer can act on it without logging in; the action links to their quotes in
 * the members area, where the full thread lives.
 */
class QuoteReadyNotification extends TenantMailNotification
{
    public function __construct(private readonly QuoteRequest $quoteRequest, Tenant $tenant)
    {
        $this->renderForTenant($tenant);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('app.mail.quote_ready.subject', ['tenant' => $this->tenant->name]))
            ->greeting(__('app.mail.quote_ready.greeting', ['name' => $notifiable->name]))
            ->line(__('app.mail.quote_ready.intro', ['tenant' => $this->tenant->name]));

        if ($this->quoteRequest->service !== null) {
            $mail->line(__('app.mail.quote_ready.service', ['service' => $this->quoteRequest->service->name]));
        }

        if ($this->quoteRequest->price_minor !== null) {
            $mail->line(__('app.mail.quote_ready.price', [
                // A quoted request always carries its currency alongside the price
                // (SubmitQuote sets both); HUF is the platform default fallback.
                'price' => $this->money(
                    $this->quoteRequest->price_minor,
                    $this->quoteRequest->currency ?? 'HUF',
                ),
            ]));
        }

        if ($this->quoteRequest->valid_until !== null) {
            $mail->line(__('app.mail.quote_ready.valid_until', [
                'date' => $this->tenantTime($this->quoteRequest->valid_until),
            ]));
        }

        return $mail
            ->action(__('app.mail.quote_ready.action'), $this->tenantUrl('/my/quotes'))
            ->line(__('app.mail.quote_ready.outro'));
    }
}
