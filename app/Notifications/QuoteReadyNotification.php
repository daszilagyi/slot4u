<?php

namespace App\Notifications;

use App\Enums\NotificationType;
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

    protected function templateType(): NotificationType
    {
        return NotificationType::QuoteReady;
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'name' => $notifiable->name,
            'tenant' => $this->tenant->name,
            'service' => $this->quoteRequest->service?->name,
            // A quoted request always carries its currency alongside the price
            // (SubmitQuote sets both); HUF is the platform default fallback.
            'price' => $this->quoteRequest->price_minor !== null
                ? $this->money($this->quoteRequest->price_minor, $this->quoteRequest->currency ?? 'HUF')
                : null,
            'date' => $this->quoteRequest->valid_until !== null ? $this->tenantTime($this->quoteRequest->valid_until) : null,
        ];
    }

    protected function templateAction(): array
    {
        return [
            __('app.mail.quote_ready.action'),
            $this->tenantUrl('/my/quotes'),
        ];
    }

    protected function defaultMail(object $notifiable): MailMessage
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

        [$actionLabel, $actionUrl] = $this->templateAction();

        return $mail
            ->action($actionLabel, $actionUrl)
            ->line(__('app.mail.quote_ready.outro'))
            ->line($this->closingLine());
    }
}
