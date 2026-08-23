<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\BookingSource;
use App\Enums\ConversionStatus;
use App\Enums\Feature;
use App\Events\BookingCreated;
use App\Models\AnalyticsConversion;
use App\Models\Booking;
use App\Services\Feature\FeatureResolver;
use App\Settings\TenantAnalyticsSettings;
use App\Support\CookieConsent;
use App\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Remembers, at booking time, that this visitor may be reported as a conversion
 * later (SLO-173).
 *
 * ⚠️ Deliberately NOT queued, and that is the whole point of the class. Three
 * things are knowable only while the visitor's own request is in hand:
 *
 *  - whether they allowed `marketing`,
 *  - their `_fbp` / `_fbc` cookies, which are how Meta attributes the sale to
 *    the ad that produced it,
 *  - the page they booked from.
 *
 * The sale itself may be confirmed hours later, by an admin, in a request that
 * has nothing to do with that visitor — reading consent there would read the
 * ADMIN's cookie and call it the customer's answer. So the decision is captured
 * here and nowhere else.
 *
 * No consent, no row. The absence of a row is the durable record of "no": there
 * is then nothing for the later stage to find, and no code path that could
 * change its mind.
 */
class RecordConversionContext
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly FeatureResolver $features,
    ) {}

    public function handle(BookingCreated $event): void
    {
        $booking = $event->booking;

        // Only a booking a visitor made themselves on the public site. An admin
        // entering a phone booking is not a website conversion, and the consent
        // cookie on that request belongs to the admin.
        if ($booking->source !== BookingSource::Online) {
            return;
        }

        $tenant = $this->tenants->current();

        if ($tenant === null || ! $this->features->enabled($tenant, Feature::Analytics)) {
            return;
        }

        $settings = TenantAnalyticsSettings::fromArray($tenant->analytics);

        if (! $settings->sendsServerConversions()) {
            return;
        }

        $request = request();

        if (! CookieConsent::fromRequest($request)->allows((string) config('analytics.tenant.meta_pixel_category'))) {
            return;
        }

        $this->record($booking, $request);
    }

    private function record(Booking $booking, Request $request): void
    {
        try {
            AnalyticsConversion::query()->create([
                'booking_id' => $booking->getKey(),
                'provider' => AnalyticsConversion::PROVIDER_META,
                'event_name' => AnalyticsConversion::EVENT_PURCHASE,
                // The booking code, which the browser Pixel also sends as its
                // `eventID`. One value, known to both halves, with nothing extra
                // to store or keep in step.
                'event_id' => $booking->code,
                'status' => ConversionStatus::Pending,
                'fbp' => $this->cookie($request, '_fbp', 128),
                'fbc' => $this->cookie($request, '_fbc', 255),
                'event_source_url' => mb_substr($request->fullUrl(), 0, 2048),
            ]);
        } catch (QueryException) {
            // The unique key fired: a row for this booking already exists. That
            // is the guarantee working, not a problem to report.
            //
            // ⚠️ This is the normal path today, not an edge case: Laravel's event
            // discovery registers every class in `app/Listeners` on top of this
            // app's explicit Event::listen calls, so this listener runs twice per
            // booking (SLO-174). The unique key is what makes that harmless.
        }
    }

    /**
     * A Meta cookie, as the browser sent it.
     *
     * Read straight off the request rather than through Laravel's cookie jar:
     * these are set by Meta's own script, so they are not in the encrypted-cookie
     * scheme and decryption would simply fail on them.
     */
    private function cookie(Request $request, string $name, int $max): ?string
    {
        $value = $request->cookies->get($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
