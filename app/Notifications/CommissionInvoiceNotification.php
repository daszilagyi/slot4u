<?php

namespace App\Notifications;

use App\Models\CommissionInvoice;
use App\Models\Tenant;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use App\Services\Mail\MailTextStore;
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

        // The superadmin's wording if edited (SLO-246), else the lang default.
        $mail = app(MailTextStore::class)->resolve("commission_invoice_{$variant}", $this->tenant->locale)->applyTo(
            (new MailMessage)->greeting(__("app.mail.commission_invoice.{$variant}.greeting", ['name' => $notifiable->name])),
            [
                'name' => $notifiable->name,
                'tenant' => $this->tenant->name,
                'period' => $this->invoice->period,
                'amount' => $this->money($this->invoice->total_gross_minor),
                'due' => $this->dueDate(),
            ],
            [__('app.mail.commission_invoice.action'), $this->billingUrl()],
        );

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

    /**
     * The tenant admin's billing area on the tenant subdomain. Built from the
     * route: the hand-written `/admin/billing` it replaced was a 404 (SLO-240).
     * The route sits outside ensure.tenant.active, so the suspension mail's
     * button still reaches it (SLO-120).
     */
    private function billingUrl(): string
    {
        return route('tenant.billing.index', ['tenant' => $this->tenant->slug]);
    }
}
