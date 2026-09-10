<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\Feature;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;
use App\Settings\TenantAnalyticsSettings;
use App\Support\ConsentScope;
use App\Support\CookieConsent;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;

/**
 * Which of the TENANT's own measurement tags load on this request (SLO-56).
 *
 * The mirror image of {@see PlatformAnalytics}, and deliberately a separate
 * class rather than a flag on it: the two answer to different data controllers.
 * Here the tenant is the controller and slot4u only serves the page (docs/19
 * §2), so what loads is the tenant's decision to make — within the visitor's.
 *
 * The two vendors are gated separately, by category:
 *
 *   GA4         → `analytics`  (being counted)
 *   Meta Pixel  → `marketing`  (being retargeted)
 *
 * A visitor who accepted measurement has not thereby accepted advertising. The
 * banner asks the two questions separately, so the answer has to be read
 * separately too — collapsing them would make one of the two toggles a lie.
 */
final class TenantAnalytics
{
    private function __construct(
        /** Null unless the tenant configured GA4 AND the visitor allowed analytics. */
        public readonly ?string $ga4MeasurementId,
        /** Null unless the tenant configured a pixel AND the visitor allowed marketing. */
        public readonly ?string $metaPixelId,
    ) {}

    public static function forRequest(Request $request): self
    {
        $settings = self::configuredSettings();

        if (! $settings instanceof TenantAnalyticsSettings) {
            return self::none();
        }

        $consent = CookieConsent::fromRequest($request);

        return new self(
            ga4MeasurementId: $settings->hasGa4()
                && $consent->allows((string) config('analytics.tenant.ga4_category'))
                    ? $settings->ga4MeasurementId
                    : null,
            metaPixelId: $settings->hasMetaPixel()
                && $consent->allows((string) config('analytics.tenant.meta_pixel_category'))
                    ? $settings->metaPixelId
                    : null,
        );
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * The consent categories whose answer can change what this tenant's public
     * pages load (SLO-218) — the same three conditions {@see forRequest()}
     * applies, minus the visitor's answer.
     *
     * Empty is the ordinary case, not an edge one: a tenant that never filled in
     * the analytics screen measures nothing, so its booking page sets no cookie
     * beyond the session, and a banner there would be asking about nothing. The
     * embedded demo (SLO-192) falls out of this rule rather than being exempted
     * from it — see {@see ConsentScope}.
     *
     * No `Request` parameter, unlike the platform side: which tenant this is was
     * settled by the middleware, and taking an argument this never reads would
     * suggest the host still had a say.
     *
     * @return list<string>
     */
    public static function gatedCategories(): array
    {
        $settings = self::configuredSettings();

        if (! $settings instanceof TenantAnalyticsSettings) {
            return [];
        }

        $categories = [];

        if ($settings->hasGa4()) {
            $categories[] = (string) config('analytics.tenant.ga4_category');
        }

        if ($settings->hasMetaPixel()) {
            $categories[] = (string) config('analytics.tenant.meta_pixel_category');
        }

        return array_values(array_unique($categories));
    }

    /**
     * The tenant's stored measurement config, or null when nothing could load
     * whatever the visitor answers: no tenant on this host, the feature switched
     * off, or nothing configured.
     *
     * ⚠️ The feature gate is checked before the settings are even read, so a
     * tenant whose analytics feature was switched off stops measuring at once —
     * rather than keeping whatever ids happen to be stored.
     */
    private static function configuredSettings(): ?TenantAnalyticsSettings
    {
        $tenant = app(TenantManager::class)->current();

        if (! $tenant instanceof Tenant) {
            return null;
        }

        if (! app(FeatureResolver::class)->enabled($tenant, Feature::Analytics)) {
            return null;
        }

        $settings = TenantAnalyticsSettings::fromArray($tenant->analytics);

        return $settings->isEmpty() ? null : $settings;
    }

    public function loadsGa4(): bool
    {
        return $this->ga4MeasurementId !== null;
    }

    public function loadsMetaPixel(): bool
    {
        return $this->metaPixelId !== null;
    }

    public function loadsAnything(): bool
    {
        return $this->loadsGa4() || $this->loadsMetaPixel();
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspOrigins(): array
    {
        $origins = [];

        if ($this->loadsGa4()) {
            $origins[] = (array) config('analytics.origins.ga4', []);
        }

        if ($this->loadsMetaPixel()) {
            $origins[] = (array) config('analytics.origins.meta_pixel', []);
        }

        return AnalyticsOrigins::merge(...$origins);
    }
}
