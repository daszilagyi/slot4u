<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Notifications\Concerns\RecordsDelivery;
use App\Notifications\Concerns\SuppressedForDemoTenant;
use App\Notifications\Concerns\TracksDelivery;
use App\Services\Notification\MessageTemplateRenderer;
use App\Settings\TenantSettings;
use App\Tenancy\TenantPublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Base for every customer-facing tenant mail (SLO-108/SLO-109): queued so a slow
 * mail transport never blocks a booking request, delivery-tracked in
 * `notifications_log`, and rendered in the tenant's locale and timezone rather
 * than the queue worker's.
 */
abstract class TenantMailNotification extends Notification implements RecordsDelivery, ShouldQueue
{
    use Queueable, SuppressedForDemoTenant, TracksDelivery;

    /**
     * The tenant this mail speaks for. Held explicitly (rather than re-read off the
     * subject's `tenant` relation at render time) because that relation hides a
     * soft-deleted tenant: on the queue worker it would resolve to null and fail the
     * render. The listener resolves an operational tenant up front and hands it in.
     */
    protected Tenant $tenant;

    /**
     * Memoised reply address, and the flag that says the lookup already ran —
     * `null` is a real answer here (the tenant has no usable address), so it
     * cannot double as "not looked up yet".
     */
    private ?string $replyAddress = null;

    private bool $replyAddressResolved = false;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The notification kind, used to look up a tenant's template override.
     */
    abstract protected function templateType(): NotificationType;

    /**
     * The `:placeholder` values available to this notification's template — every
     * variable its default body can reference, so a tenant override may use any of
     * them. Always include `name` (the greeting) and `tenant`.
     *
     * @return array<string, string|int|null>
     */
    abstract protected function templateVars(object $notifiable): array;

    /**
     * The call-to-action button [label, url]. Kept out of the editable body so a
     * tenant override can't break the link; it is appended after the body either way.
     *
     * @return array{0: string, 1: string}
     */
    abstract protected function templateAction(): array;

    /**
     * The built-in mail, rendered from lang keys — used when the tenant has no
     * enabled override for this kind. This is the notification's original body.
     */
    abstract protected function defaultMail(object $notifiable): MailMessage;

    /**
     * Render the mail: a tenant's enabled template override if one exists, otherwise
     * the built-in default. Both paths end in the same CTA button.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $override = app(MessageTemplateRenderer::class)
            ->resolve($this->tenant, $this->templateType(), $this->locale);

        $mail = $override === null
            ? $this->defaultMail($notifiable)
            : $this->renderFromTemplate($override, $notifiable);

        // The demo diversion goes last, so it applies to the tenant's own
        // template override exactly as it does to the built-in body (SLO-182).
        return $this->suppressWhenDemo($this->addressAsTenant($mail), $this->tenant);
    }

    /**
     * Make the mail look — and behave — like it came from the tenant (SLO-171).
     *
     * ⚠️ The ADDRESS stays the platform's. Sending as the tenant's own domain is
     * what fails SPF and DKIM (docs/11 §8, docs/17 §8): we hold neither an SPF
     * authorisation nor a signing key for a domain we do not control, and a
     * confirmation in the spam folder is worth less than one with the wrong name
     * on it. Only the DISPLAY NAME changes.
     *
     * The display name is not cosmetic either. A customer waiting to hear from
     * their hairdresser sees "slot4u" in the inbox list and does not open it —
     * which costs the same as the spam folder, one step later.
     *
     * `Reply-To` is the half that was actually broken: the mails invite a reply
     * in their own text, and until this it went to an unattended mailbox called
     * no-reply.
     */
    protected function addressAsTenant(MailMessage $mail): MailMessage
    {
        $mail->from((string) config('mail.from.address'), $this->tenant->name);

        $replyTo = $this->tenantReplyAddress();

        if ($replyTo !== null) {
            $mail->replyTo($replyTo, $this->tenant->name);
        }

        return $mail;
    }

    /**
     * Where a customer's reply should land, or null if there is nowhere to send
     * it (SLO-171).
     *
     * The tenant's company-profile email — already collected, already validated
     * as an address, and already the one shown publicly on its booking page. A
     * dedicated `reply_to_email` column would be a second thing to fill in, and
     * a tenant who filled in only one of the two would end up with a mail
     * pointing somewhere they do not read.
     *
     * ⚠️ Never the platform's own address. A tenant that typed our no-reply into
     * its profile would otherwise get a `Reply-To` header that promises a human
     * and delivers a mailbox nobody opens — worse than no header, because the
     * absence of one at least makes the mail client say so.
     */
    protected function tenantReplyAddress(): ?string
    {
        if ($this->replyAddressResolved) {
            return $this->replyAddress;
        }

        $this->replyAddressResolved = true;
        $email = TenantSettings::fromArray($this->tenant->settings)->email;

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->replyAddress = null;
        }

        if (strcasecmp($email, (string) config('mail.from.address')) === 0) {
            return $this->replyAddress = null;
        }

        return $this->replyAddress = $email;
    }

    /**
     * The closing line, and the reason it lives here rather than in seven lang
     * keys (SLO-171).
     *
     * Whether the mail may ask for a reply is decided by the same method that
     * decides whether it carries a `Reply-To`. Seven copies of "válaszolj erre az
     * emailre" scattered across seven notifications is how the text and the
     * header drifted apart in the first place — the mails asked for something the
     * headers could not deliver.
     *
     * With no reply address the line does not merely go quiet: it says the mail
     * is unattended and points at the tenant's own page, which is where the
     * contact details actually are.
     */
    protected function closingLine(): string
    {
        return $this->tenantReplyAddress() !== null
            ? __('app.mail.reply.invite', ['tenant' => $this->tenant->name])
            : __('app.mail.reply.contact', [
                'tenant' => $this->tenant->name,
                'url' => $this->tenantUrl('/'),
            ]);
    }

    /**
     * Build the mail from a tenant override: subject + body with placeholders
     * substituted, an automatic greeting, and the fixed CTA button.
     */
    protected function renderFromTemplate(MessageTemplate $template, object $notifiable): MailMessage
    {
        $renderer = app(MessageTemplateRenderer::class);
        $vars = $this->templateVars($notifiable);
        [$actionLabel, $actionUrl] = $this->templateAction();

        $mail = (new MailMessage)
            ->subject($renderer->substitute($template->subject, $vars))
            ->greeting($renderer->substitute(__('app.mail.greeting'), $vars));

        foreach ($renderer->bodyLines($renderer->substitute($template->body, $vars)) as $line) {
            $mail->line($line);
        }

        // The closing goes on the override too. A tenant's own body may well end
        // with "call us" or nothing at all; what must not happen is a template
        // that invites a reply on a tenant with no reply address. One line,
        // decided by the same method as the header.
        return $mail->action($actionLabel, $actionUrl)->line($this->closingLine());
    }

    /**
     * Bind the mail to its tenant and pin the render locale to the tenant's, so a
     * queue worker running under a different app locale still mails the customer in
     * their language. Call from the constructor of every subclass.
     */
    protected function renderForTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->locale = $tenant->locale;
    }

    /**
     * An absolute URL on the tenant's public host — its verified primary custom
     * domain when it has one, the `{slug}.{central_domain}` subdomain otherwise
     * (SLO-42). The mail is sent from queue jobs with no bound request, so the
     * host is built from the tenant rather than taken from the current URL.
     */
    protected function tenantUrl(string $path): string
    {
        return app(TenantPublicUrl::class)->to($this->tenant, $path);
    }

    /**
     * A stored (UTC) instant rendered in the tenant's timezone (docs/01 §7).
     */
    protected function tenantTime(Carbon $at): string
    {
        return $at->copy()->timezone($this->tenant->timezone)->format('Y-m-d H:i');
    }

    /**
     * Money for display: minor units (fillér/cent) formatted in the tenant's locale.
     */
    protected function money(int $minor, string $currency): string
    {
        return (string) Number::currency($minor / 100, $currency, $this->tenant->locale);
    }
}
