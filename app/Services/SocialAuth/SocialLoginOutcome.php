<?php

declare(strict_types=1);

namespace App\Services\SocialAuth;

use App\Actions\SocialAuth\ResolveSocialLogin;

/**
 * What {@see ResolveSocialLogin} decided on the
 * starting host (SLO-251, docs/28): a user to sign in, or the lang key of a
 * message to show — never both.
 */
final readonly class SocialLoginOutcome
{
    private function __construct(
        public ?int $userId,
        public ?string $error,
    ) {}

    public static function signIn(int $userId): self
    {
        return new self($userId, null);
    }

    /** @param  string  $error  a key under `auth.social.errors` */
    public static function fail(string $error): self
    {
        return new self(null, $error);
    }
}
