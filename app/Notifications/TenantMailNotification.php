<?php

namespace App\Notifications;

use App\Models\Tenant;
use App\Notifications\Concerns\RecordsDelivery;
use App\Notifications\Concerns\TracksDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
