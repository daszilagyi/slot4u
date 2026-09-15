<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The OAuth providers a person can sign in with (SLO-251, docs/28).
 *
 * The value is the Socialite driver name and the `services.{value}` config key.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Facebook = 'facebook';

    /** The brand name, as the provider writes it (not translated). */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Facebook => 'Facebook',
        };
    }

    /**
     * Whether the credentials are present. An unconfigured provider shows no
     * button and its routes 404, so dev, CI and a production host that has not
     * been given the keys yet behave as if the feature did not exist.
     */
    public function isConfigured(): bool
    {
        return filled(config("services.{$this->value}.client_id"))
            && filled(config("services.{$this->value}.client_secret"));
    }

    /**
     * Whether an e-mail address this provider returns has been verified by it.
     *
     * Google says so per address (`email_verified`). Facebook only ever returns
     * a confirmed address and has no flag for it, so a present address counts
     * as verified — Daniel's decision on SLO-250.
     *
     * @param  array<string, mixed>  $raw  the provider's user payload
     */
    public function vouchesFor(array $raw): bool
    {
        return match ($this) {
            self::Google => filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            self::Facebook => true,
        };
    }

    /** @return list<string> */
    public static function configured(): array
    {
        return array_values(array_map(
            fn (self $provider): string => $provider->value,
            array_filter(self::cases(), fn (self $provider): bool => $provider->isConfigured()),
        ));
    }
}
