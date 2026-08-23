<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Typed view over a tenant's `analytics` column (SLO-56): which measurement
 * accounts its public booking pages report to.
 *
 * These are the TENANT's accounts, not slot4u's. On a tenant host the tenant is
 * the data controller and slot4u the processor (docs/19 §2) — the platform's own
 * property (SLO-172) is never emitted here, and this one is never emitted on the
 * marketing site.
 *
 * Both ids reach the browser: they are printed into the page, so treating them
 * as secrets would be theatre. The access token does NOT — it is the credential
 * that lets anyone post conversions into the tenant's ad account, and it is why
 * the whole column is encrypted at rest.
 */
final class TenantAnalyticsSettings
{
    /**
     * `G-` followed by the property's short id. Anchored, because the value is
     * interpolated into a script URL — a rejected id is a tenant filling in a
     * form wrong, an accepted arbitrary string is a stored injection.
     */
    public const GA4_PATTERN = '/^G-[A-Z0-9]{4,20}$/i';

    /** Meta dataset ids are numeric and currently 15–16 digits; allow some room. */
    public const META_PIXEL_PATTERN = '/^[0-9]{10,20}$/';

    public function __construct(
        public readonly ?string $ga4MeasurementId = null,
        public readonly ?string $metaPixelId = null,
        /**
         * The Conversions API access token (SLO-173). A SECRET: it never reaches
         * an Inertia prop — the settings screen only ever learns whether one is
         * set ({@see hasMetaAccessToken()}).
         */
        public readonly ?string $metaAccessToken = null,
        /**
         * Meta's test event code. Present only while a tenant is verifying the
         * integration in Events Manager; events carrying it show up in the test
         * view instead of counting as real conversions.
         */
        public readonly ?string $metaTestEventCode = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];

        return new self(
            // Re-validated on the way out, not only on the way in. A value that
            // predates a tightened rule, or one written by a seeder or a console
            // command that skipped the form request, must not reach a <script
            // src> just because it is already in the database.
            ga4MeasurementId: self::matching($data, 'ga4_measurement_id', self::GA4_PATTERN),
            metaPixelId: self::matching($data, 'meta_pixel_id', self::META_PIXEL_PATTERN),
            // Not pattern-matched: Meta does not document a stable shape for it,
            // and a rule invented here would reject a valid token the day they
            // change the format. A wrong one fails loudly on the first call.
            metaAccessToken: self::str($data, 'meta_access_token'),
            metaTestEventCode: self::str($data, 'meta_test_event_code'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ga4_measurement_id' => $this->ga4MeasurementId,
            'meta_pixel_id' => $this->metaPixelId,
            'meta_access_token' => $this->metaAccessToken,
            'meta_test_event_code' => $this->metaTestEventCode,
        ];
    }

    public function hasGa4(): bool
    {
        return $this->ga4MeasurementId !== null;
    }

    public function hasMetaPixel(): bool
    {
        return $this->metaPixelId !== null;
    }

    public function hasMetaAccessToken(): bool
    {
        return $this->metaAccessToken !== null;
    }

    /**
     * Whether server-side conversions can be sent at all (SLO-173). Both halves
     * are needed: the pixel says WHERE, the token says WITH WHAT.
     */
    public function sendsServerConversions(): bool
    {
        return $this->hasMetaPixel() && $this->hasMetaAccessToken();
    }

    /** Whether the tenant has configured any measurement at all. */
    public function isEmpty(): bool
    {
        return ! $this->hasGa4() && ! $this->hasMetaPixel();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function matching(array $data, string $key, string $pattern): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && preg_match($pattern, $value) === 1 ? $value : null;
    }
}
