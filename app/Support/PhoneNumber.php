<?php

namespace App\Support;

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use DateTimeZone;
use Exception;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone numbers, in one place (SLO-151).
 *
 * Every phone field the app accepts is stored in E.164 (`+36301234567`), so the
 * number a guest types on the public booking form is already the string an SMS
 * gateway would dial. Validation and normalization are deliberately the SAME
 * operation — a number is valid exactly when it can be turned into E.164 — so the
 * two cannot drift apart and leave a value that passes validation in a shape
 * nothing downstream can use.
 *
 * Google's libphonenumber does the parsing. A hand-written regex would either
 * narrow the field to Hungarian numbers (locking out foreign guests, which a
 * booking system cannot do) or accept anything with enough digits; the
 * per-country metadata is the entire point.
 */
final class PhoneNumber
{
    /**
     * Dialling region assumed when the tenant's own timezone names no country,
     * and the region whose example number illustrates the error message.
     */
    public const DEFAULT_REGION = 'HU';

    /**
     * The E.164 form of $value, or null when it is not a real phone number.
     *
     * $region (ISO 3166-1 alpha-2) is consulted only for numbers typed without a
     * country code: `06 30 123 4567` is Hungarian on a Hungarian tenant's site,
     * while `+43 …` is Austrian wherever it is typed. `0043 …` works too — the
     * region supplies the international dialling prefix to strip.
     *
     * Validity is judged against the country the number's own prefix implies, not
     * against $region, so a foreign number is never rejected for being foreign.
     */
    public static function toE164(string $value, string $region): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($value, $region);
        } catch (NumberParseException) {
            return null;
        }

        return $util->isValidNumber($parsed)
            ? $util->format($parsed, PhoneNumberFormat::E164)
            : null;
    }

    /**
     * What to store for the raw $value a form submitted: E.164 when it parses,
     * null when the (optional) field was left blank, and the trimmed input
     * verbatim when it is not a number — so the Phone rule is the thing that
     * reports it, and the form echoes back what the visitor actually typed.
     */
    public static function normalizeInput(?string $value, string $region): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : self::toE164($value, $region) ?? $value;
    }

    /**
     * The dialling region to assume for a number typed without a country code.
     *
     * A tenant has no country column, but it does have a timezone, and the tz
     * database records which country owns each zone (Europe/Budapest → HU). That
     * is a real mapping, unlike the locale, which names a language: `hu` happens
     * to look like HU while `en` names no country at all. Zones with no country
     * (UTC) fall back to the platform default.
     */
    public static function regionFor(?Tenant $tenant = null): string
    {
        $tenant ??= app(TenantManager::class)->current();

        return self::regionForTimezone($tenant?->timezone);
    }

    /**
     * The region behind a timezone name, for callers that hold the timezone but
     * not the model — the backfill migration walks raw rows.
     */
    public static function regionForTimezone(?string $timezone): string
    {
        $fallback = strtoupper((string) config('tenancy.default_phone_region', self::DEFAULT_REGION));
        $fallback = self::isRegion($fallback) ? $fallback : self::DEFAULT_REGION;

        if ($timezone === null || $timezone === '') {
            return $fallback;
        }

        try {
            $location = (new DateTimeZone($timezone))->getLocation();
        } catch (Exception) {
            return $fallback;
        }

        // `??` would be dead code: getLocation() either returns false or an array
        // that always carries country_code — as '??' for a zone owning no country.
        $country = is_array($location) ? strtoupper($location['country_code']) : '';

        return self::isRegion($country) ? $country : $fallback;
    }

    /**
     * A real, well-formed mobile number for $region. The rejection message shows
     * the shape we want rather than only saying "invalid" — a guest who is told
     * their number is wrong and not what right looks like just abandons the form.
     */
    public static function example(string $region): string
    {
        $util = PhoneNumberUtil::getInstance();
        $example = $util->getExampleNumberForType($region, PhoneNumberType::MOBILE)
            ?? $util->getExampleNumberForType(self::DEFAULT_REGION, PhoneNumberType::MOBILE);

        return $example === null ? '' : $util->format($example, PhoneNumberFormat::INTERNATIONAL);
    }

    private static function isRegion(string $country): bool
    {
        return preg_match('/^[A-Z]{2}$/', $country) === 1;
    }
}
