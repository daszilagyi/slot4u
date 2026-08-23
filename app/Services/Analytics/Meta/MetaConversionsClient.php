<?php

declare(strict_types=1);

namespace App\Services\Analytics\Meta;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts one conversion event to Meta's Conversions API (SLO-173).
 *
 * The whole reason this exists alongside the browser Pixel: the Pixel is what an
 * adblocker, Safari's ITP or a closed tab removes. This half runs on our own
 * server and reports the sale that actually happened.
 *
 * ⚠️ Everything that identifies a person is SHA-256 hashed before it leaves,
 * which is what Meta's API expects and also what keeps this defensible: slot4u
 * hands over a match key, not a customer list. The hashes are computed here and
 * kept nowhere — not in the conversion row, not in a log.
 */
final class MetaConversionsClient
{
    public function __construct(
        private readonly string $pixelId,
        private readonly string $accessToken,
        private readonly ?string $testEventCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $userData  raw values; hashed here, never by the caller
     * @param  array<string, mixed>  $customData
     *
     * @throws RuntimeException when Meta refuses the event
     */
    public function send(
        string $eventName,
        string $eventId,
        int $eventTime,
        array $userData,
        array $customData,
        ?string $eventSourceUrl = null,
    ): void {
        $payload = [
            'data' => [array_filter([
                'event_name' => $eventName,
                'event_time' => $eventTime,
                // The deduplication key. The browser Pixel sends the same value
                // as its `eventID`, so Meta counts one conversion even when both
                // halves arrive — and still counts one when only this half does.
                'event_id' => $eventId,
                'event_source_url' => $eventSourceUrl,
                // `website`, not `system_generated`: the booking was made by a
                // person on a page, even though this report is sent from a
                // server. Meta uses this to decide how to attribute it.
                'action_source' => 'website',
                'user_data' => $this->hashUserData($userData),
                'custom_data' => $customData,
            ], static fn ($value): bool => $value !== null && $value !== [])],
            'access_token' => $this->accessToken,
        ];

        if ($this->testEventCode !== null) {
            $payload['test_event_code'] = $this->testEventCode;
        }

        $response = $this->request()->post($this->endpoint(), $payload);

        if ($response->successful()) {
            return;
        }

        // Meta returns its complaint as a structured error; surfacing the message
        // is the difference between "the integration is broken" and "the token
        // expired on the 3rd". The exception is what the job records and retries
        // on, so the tenant's screen can eventually say which.
        $message = (string) ($response->json('error.message') ?? $response->body());

        throw new RuntimeException(sprintf(
            'Meta Conversions API refused the event (HTTP %d): %s',
            $response->status(),
            $message,
        ));
    }

    /**
     * Meta wants each identifier normalised and then SHA-256 hashed; `fbp` and
     * `fbc` are its own cookie values and go through as they are.
     *
     * Normalisation matters more than it looks: Meta matches on the hash, so
     * "  Anna@Example.COM " and "anna@example.com" are different people unless
     * they are trimmed and lowercased first — and a mismatched hash is not an
     * error anyone sees, just a conversion that quietly fails to attribute.
     *
     * @param  array<string, mixed>  $userData
     * @return array<string, mixed>
     */
    private function hashUserData(array $userData): array
    {
        $hashed = [];

        foreach (['em' => 'email', 'ph' => 'phone', 'fn' => 'first_name'] as $key => $field) {
            $value = $userData[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $hashed[$key] = [hash('sha256', $this->normalise($field, $value))];
        }

        foreach (['fbp', 'fbc'] as $passthrough) {
            $value = $userData[$passthrough] ?? null;

            if (is_string($value) && $value !== '') {
                $hashed[$passthrough] = $value;
            }
        }

        return $hashed;
    }

    private function normalise(string $field, string $value): string
    {
        $value = mb_strtolower(trim($value));

        // A phone number matches on digits only — no plus, no spaces, no dashes.
        // slot4u stores E.164 (SLO-151), so stripping the punctuation is all it
        // takes to reach the form Meta hashes.
        return $field === 'phone'
            ? (string) preg_replace('/\D+/', '', $value)
            : $value;
    }

    private function endpoint(): string
    {
        return sprintf(
            '%s/%s/%s/events',
            rtrim((string) config('analytics.meta.graph_url'), '/'),
            (string) config('analytics.meta.api_version'),
            $this->pixelId,
        );
    }

    private function request(): PendingRequest
    {
        // A short ceiling on purpose: this runs inside a queued job with its own
        // retry and backoff, so waiting a minute on a wedged connection buys
        // nothing the job does not already provide — and it holds a worker.
        return Http::acceptJson()
            ->timeout((int) config('analytics.meta.timeout', 15))
            ->connectTimeout(10);
    }
}
