<?php

declare(strict_types=1);

namespace App\Actions\SocialAuth;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAuth\SocialIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * A signed-in user adding a Google / Facebook identity on their profile
 * (SLO-252, docs/28 §4).
 *
 * No e-mail rule here, unlike sign-in: the person is already authenticated as
 * the account, and proves the identity by signing in at the provider in the
 * same browser (the flow's nonce). A Facebook identity without an address may
 * therefore be linked this way.
 *
 * Returns null on success, or the lang key (`auth.social.errors.*`) of why not.
 */
final class LinkSocialIdentity
{
    public function __invoke(User $user, SocialIdentity $identity): ?string
    {
        $existing = SocialAccount::query()
            ->withoutGlobalScopes()
            ->where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->id)
            ->first();

        if ($existing !== null) {
            // Linked to this very account already: nothing to do. Linked to
            // anybody else: moving it would hand that account's sign-in over.
            return $existing->user_id === $user->id ? null : 'identity_taken';
        }

        $hasProvider = SocialAccount::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('provider', $identity->provider->value)
            ->exists();

        if ($hasProvider) {
            return 'provider_already_linked';
        }

        try {
            self::attach($user, $identity);
        } catch (UniqueConstraintViolationException) {
            return 'try_again';
        }

        Log::info('Social identity linked from the profile', [
            'provider' => $identity->provider->value,
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
        ]);

        return null;
    }

    /** Write the link row — the one place a `social_accounts` row is created. */
    public static function attach(User $user, SocialIdentity $identity): SocialAccount
    {
        return SocialAccount::query()->withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'provider' => $identity->provider,
            'provider_user_id' => $identity->id,
            'email' => $identity->email,
            'name' => $identity->name,
            'avatar_url' => $identity->avatarUrl,
        ]);
    }
}
