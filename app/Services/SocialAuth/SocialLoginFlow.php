<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use App\Enums\SocialIntent;
use App\Enums\SocialProvider;

/**
 * One sign-in attempt with Google or Facebook, from the button click to the
 * provider's callback (SLO-251, docs/28).
 *
 * Everything that decides where the person ends up is fixed here, on the host
 * they started from, BEFORE they leave for the provider: the tenant, the
 * absolute consume URL on that same host, and a relative return path. The
 * callback only ever reads it — nothing the provider or the browser sends back
 * can move a login to a different host.
 */
final readonly class SocialLoginFlow
{
    public function __construct(
        public SocialProvider $provider,
        public SocialIntent $intent,
        /** Null when the flow started on the central domain. */
        public ?int $tenantId,
        /** Absolute URL of the consume endpoint on the starting host. */
        public string $consumeUrl,
        /** Relative path to land on afterwards, or null for the user's home. */
        public ?string $returnPath,
        /** sha256 of the nonce held in the starting host's session. */
        public string $nonceHash,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'intent' => $this->intent->value,
            'tenant_id' => $this->tenantId,
            'consume_url' => $this->consumeUrl,
            'return_path' => $this->returnPath,
            'nonce_hash' => $this->nonceHash,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            SocialProvider::from((string) $data['provider']),
            SocialIntent::from((string) $data['intent']),
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            (string) $data['consume_url'],
            isset($data['return_path']) ? (string) $data['return_path'] : null,
            (string) $data['nonce_hash'],
        );
    }
}
