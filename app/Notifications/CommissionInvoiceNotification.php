<?php

namespace App\Notifications;

use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * Emails a tenant admin about their monthly commission invoice (docs/10 §6.5/6.6,
 * §11). One of three variants: `issued` when it is first raised, `overdue` as the
 * dunning reminder once the due date passes, and `suspended` when non-payment past
 * the grace window suspends the tenant. Rendered in the tenant's locale regardless
 * of queue context; amounts are the gross total in the tenant's currency.
 */
class CommissionInvoiceNotification extends Notification
{
    use Queueable, SuppressedForDemoTenant;

    public const string ISSUED = 'issued';

    public const string OVERDUE = 'overdue';

    public const string SUSPENDED = 'suspended';

    public function __construct(
        private readonly CommissionInvoice $invoice,
        private readonly Tenant $tenant,
        private readonly string $variant = self::ISSUED,
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
        $variant = $this->variant;

        $mail = (new MailMessage)
            ->subject(__("app.mail.commission_invoice.{$variant}.subject", ['period' => $this->invoice->period]))
            ->greeting(__("app.mail.commission_invoice.{$variant}.greeting", ['name' => $notifiable->name]))
            ->line(__("app.mail.commission_invoice.{$variant}.intro", [
                'tenant' => $this->tenant->name,
                'period' => $this->invoice->period,
            ]))
            ->line(__('app.mail.commission_invoice.amount', [
                'amount' => $this->money($this->invoice->total_gross_minor),
                'due' => $this->dueDate(),
            ]))
            ->action(__('app.mail.commission_invoice.action'), $this->billingUrl())
            ->line(__("app.mail.commission_invoice.{$variant}.outro"));

        return $this->suppressWhenDemo($mail, $this->tenant);
    }

    /** Gross amount formatted in the tenant's locale + currency (display only). */
    private function money(int $minor): string
    {
        return (string) Number::currency($minor / 100, in: $this->invoice->currency, locale: $this->tenant->locale);
    }

    /** Payment due date rendered in the tenant's timezone. */
    private function dueDate(): string
    {
        return $this->invoice->due_at?->copy()->setTimezone($this->tenant->timezone)->isoFormat('LL') ?? '';
    }

    /** The tenant admin's billing area on the tenant subdomain. */
    private function billingUrl(): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $this->tenant->slug.'.'.config('tenancy.central_domain');

        return sprintf('%s://%s/admin/billing', $scheme, $host);
    }
}
