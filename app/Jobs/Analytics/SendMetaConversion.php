<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Enums\ConversionStatus;
use App\Models\AnalyticsConversion;
use App\Services\Analytics\Meta\MetaConversionsClient;
use App\Settings\TenantAnalyticsSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reports one sale to Meta from the server (SLO-173).
 *
 * Queued, and that is not an optimisation. docs/08 states the rule: an
 * integration outage must never block a booking. By the time this runs the
 * booking is already made and confirmed; Meta being down, slow or cross about a
 * token can only ever cost a conversion report, never a customer.
 *
 * Idempotent twice over. The row must still be `queued` — the state a listener
 * claimed it into with an atomic update — so a duplicated job finds it already
 * moved on and does nothing. And if one ever slips through anyway, the event id
 * is the booking code, which is exactly what Meta deduplicates on.
 */
class SendMetaConversion implements ShouldQueue
{
    use Queueable;

    /**
     * Five attempts over roughly ten minutes. Meta's own guidance is that events
     * are attributable for days, so there is no value in hammering — and every
     * plausible failure here (a rate limit, a blip, a deploy) resolves on that
     * scale or not at all.
     *
     * @var int
     */
    public $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300, 600];

    public function __construct(private readonly int $conversionId) {}

    public function handle(): void
    {
        // Without the tenant scope: a queue worker holds no tenant, and the row
        // carries the only tenant this job is about.
        $conversion = AnalyticsConversion::withoutGlobalScopes()
            ->with(['booking', 'tenant'])
            ->find($this->conversionId);

        if ($conversion === null || $conversion->status !== ConversionStatus::Queued) {
            // Already sent, already given up on, or deleted by an erasure that
            // ran while this sat in the queue. All three mean "not ours to send".
            return;
        }

        $booking = $conversion->booking;
        $tenant = $conversion->tenant;

        if ($booking === null || $tenant === null) {
            return;
        }

        $settings = TenantAnalyticsSettings::fromArray($tenant->analytics);

        if (! $settings->sendsServerConversions()) {
            // The tenant removed the pixel or the token between the booking and
            // now. That is a withdrawal, not an error: drop the event rather than
            // retrying it into a configuration that no longer exists.
            $this->finish($conversion, ConversionStatus::Failed, 'measurement configuration removed');

            return;
        }

        $client = new MetaConversionsClient(
            pixelId: (string) $settings->metaPixelId,
            accessToken: (string) $settings->metaAccessToken,
            testEventCode: $settings->metaTestEventCode,
        );

        $conversion->increment('attempts');

        $client->send(
            eventName: $conversion->event_name,
            eventId: $conversion->event_id,
            // The moment the sale happened, not the moment the queue got round
            // to it — a worker backlog must not move a conversion into the wrong
            // reporting day.
            eventTime: (int) ($booking->updated_at?->getTimestamp() ?? $booking->created_at?->getTimestamp() ?? time()),
            userData: [
                // Hashed inside the client and kept nowhere. slot4u hands Meta a
                // match key, not a customer list.
                'email' => $booking->guest_email ?? $booking->customer?->email,
                'phone' => $booking->guest_phone ?? $booking->customer?->phone,
                'fbp' => $conversion->fbp,
                'fbc' => $conversion->fbc,
            ],
            customData: [
                'currency' => $booking->currency,
                'value' => round($booking->price_minor / 100, 2),
            ],
            eventSourceUrl: $conversion->event_source_url,
        );

        $conversion->forgetBrowserIdentifiers();
        $this->finish($conversion, ConversionStatus::Sent, null);
    }

    /**
     * Out of retries. Recorded on the row rather than only in the log, because
     * the question a tenant eventually asks is "why did my conversions stop",
     * and the answer has to be attached to the thing that stopped.
     */
    public function failed(Throwable $exception): void
    {
        $conversion = AnalyticsConversion::withoutGlobalScopes()->find($this->conversionId);

        if ($conversion === null || $conversion->status !== ConversionStatus::Queued) {
            return;
        }

        $this->finish($conversion, ConversionStatus::Failed, $exception->getMessage());

        Log::warning('Meta conversion permanently failed', [
            'conversion_id' => $conversion->getKey(),
            'tenant_id' => $conversion->tenant_id,
            // The booking code, not the customer. Enough to trace, nothing to leak.
            'event_id' => $conversion->event_id,
        ]);
    }

    private function finish(AnalyticsConversion $conversion, ConversionStatus $status, ?string $error): void
    {
        $conversion->forceFill([
            'status' => $status,
            'last_error' => $error === null ? null : mb_substr($error, 0, 1000),
            'sent_at' => $status === ConversionStatus::Sent ? now() : null,
        ])->save();
    }
}
