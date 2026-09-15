<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use App\Enums\SocialProvider;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * The provider's answer to "who is this", reduced to what sign-in needs
 * (SLO-251). No tokens: they are dropped here and never stored.
 */
final readonly class SocialIdentity
{
    public function __construct(
        public SocialProvider $provider,
        public string $id,
        /** Lower-cased; null when the provider returned none (Facebook can). */
        public ?string $email,
        /** Whether the provider vouches for the address. */
        public bool $emailVerified,
        public ?string $name,
        public ?string $avatarUrl,
    ) {}

    public static function fromSocialite(SocialProvider $provider, SocialiteUser $user): self
    {
        $email = $user->getEmail();
        $email = is_string($email) && $email !== '' ? Str::lower($email) : null;

        /** @var array<string, mixed> $raw */
        $raw = property_exists($user, 'user') && is_array($user->user) ? $user->user : [];

        $avatar = $user->getAvatar();

        return new self(
            provider: $provider,
            id: (string) $user->getId(),
            email: $email,
            emailVerified: $email !== null && $provider->vouchesFor($raw),
            name: filled($user->getName()) ? Str::limit((string) $user->getName(), 255, '') : null,
            avatarUrl: is_string($avatar) && strlen($avatar) <= 2048 ? $avatar : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'id' => $this->id,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            SocialProvider::from((string) $data['provider']),
            (string) $data['id'],
            isset($data['email']) ? (string) $data['email'] : null,
            (bool) ($data['email_verified'] ?? false),
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['avatar_url']) ? (string) $data['avatar_url'] : null,
        );
    }
}
