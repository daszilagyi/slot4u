<?php

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\MessageTemplate;
use App\Models\Tenant;
use App\Notifications\Concerns\RecordsDelivery;
use App\Notifications\Concerns\TracksDelivery;
use App\Services\Notification\MessageTemplateRenderer;
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
    use Queueable, TracksDelivery;

    /**
     * The tenant this mail speaks for. Held explicitly (rather than re-read off the
     * subject's `tenant` relation at render time) because that relation hides a
     * soft-deleted tenant: on the queue worker it would resolve to null and fail the
     * render. The listener resolves an operational tenant up front and hands it in.
     */
    protected Tenant $tenant;

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

        if ($override === null) {
            return $this->defaultMail($notifiable);
        }

        return $this->renderFromTemplate($override, $notifiable);
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

        return $mail->action($actionLabel, $actionUrl);
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
     * An absolute URL on the tenant's public subdomain (`{slug}.{central_domain}`).
     * The mail is sent from queue jobs with no bound request, so the host is built
     * from the tenant rather than taken from the current URL.
     */
    protected function tenantUrl(string $path): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
        $host = $this->tenant->slug.'.'.config('tenancy.central_domain');

        return sprintf('%s://%s%s', $scheme, $host, $path);
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
