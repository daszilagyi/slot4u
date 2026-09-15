<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

/**
 * Meta's `signed_request` (SLO-253): `base64url(signature).base64url(payload)`,
 * the signature an HMAC-SHA256 of the ENCODED payload with the app secret.
 *
 * https://developers.facebook.com/docs/games/gamesonfacebook/login#parsingsr
 *
 * Returns the decoded payload only when the signature checks out and the
 * payload says it was signed the way we verify it; null for anything else.
 * The algorithm field is checked too — a payload claiming a different algorithm
 * was not signed the way this function verifies, whatever its bytes happen to
 * match.
 */
final class FacebookSignedRequest
{
    /** @return array<string, mixed>|null */
    public static function parse(string $signedRequest, string $secret): ?array
    {
        if ($secret === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest, 2);

        $signature = self::decode($encodedSignature);
        $json = self::decode($encodedPayload);

        if ($signature === null || $json === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $secret, true);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($json, true);

        if (! is_array($payload) || strtoupper((string) ($payload['algorithm'] ?? '')) !== 'HMAC-SHA256') {
            return null;
        }

        return $payload;
    }

    private static function decode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
