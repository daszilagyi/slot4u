<?php

namespace App\Support\Monitoring;

/**
 * Replaces contact details in free text on their way out to Sentry (SLO-153).
 *
 * Structured customer data never leaves — request bodies are not collected and
 * the user context is reduced to an id. What remains is *prose*: exception
 * messages, log lines, breadcrumb text. Those legitimately quote an address the
 * app failed to mail or a number it failed to parse, and prose cannot be filtered
 * by field name.
 *
 * So this is a second line, not the first one. It matches the two identifiers
 * that are both recognisable and directly personal — email addresses and phone
 * numbers — and deliberately does not attempt names: a regex that tried would
 * either miss most of them or eat half the message, and pretending otherwise
 * would be worse than being clear about the boundary.
 */
final class PiiRedactor
{
    public const EMAIL = '[redacted-email]';

    public const PHONE = '[redacted-phone]';

    /**
     * Phone numbers are stored E.164 since SLO-151 (`+36301234567`), so the
     * leading `+` is the reliable anchor. Separators are allowed because a
     * message may quote what the user typed rather than what was stored.
     */
    private const PHONE_PATTERN = '/\+\d[\d\s().-]{5,20}\d/';

    private const EMAIL_PATTERN = '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u';

    public static function text(string $value): string
    {
        // Email first: an address is not a phone number, but a phone pattern
        // could bite into one that contains digits.
        $value = (string) preg_replace(self::EMAIL_PATTERN, self::EMAIL, $value);

        return (string) preg_replace(self::PHONE_PATTERN, self::PHONE, $value);
    }

    /**
     * Walks an arbitrary nested structure, redacting every string in it.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function deep(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = self::text($value);
            } elseif (is_array($value)) {
                $values[$key] = self::deep($value);
            }
        }

        return $values;
    }
}
