<?php

declare(strict_types=1);

namespace App\Actions\SocialAuth;

use App\Actions\Customer\CreateCustomer;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialAuth\SocialIdentity;
use App\Services\SocialAuth\SocialLoginOutcome;
use App\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Who a Google / Facebook identity signs in as (SLO-251, docs/28).
 *
 * In this order, and the order is the security model:
 *
 * 1. **An identity already linked** signs in as its user.
 * 2. **An address the provider vouches for** that matches an account links to
 *    it and signs in. Only a vouched-for address: an unverified one would let
 *    anybody who can put a victim's address on a provider account walk into
 *    the victim's slot4u account.
 * 3. **Otherwise, on a tenant host, a CUSTOMER is created** for that tenant.
 *    Nothing else is ever created. There is no input that asks for a role, so
 *    no request can produce a tenant-admin or staff account through here —
 *    staff exist only because somebody with the permission created them.
 *
 * Whichever account is found must be admissible where the flow started: never a
 * super-admin (the admin panel is password + 2FA only), never an erased account,
 * and on a tenant host never another tenant's user. That last refusal carries
 * the same neutral message as the others, so the button cannot be used to learn
 * whether an address has an account with some other business.
 */
final class ResolveSocialLogin
{
    public function __construct(
        private readonly CreateCustomer $createCustomer,
        private readonly TenantManager $tenants,
    ) {}

    /** @param  Tenant|null  $tenant  the tenant whose host started the flow; null on the central domain */
    public function __invoke(SocialIdentity $identity, ?Tenant $tenant): SocialLoginOutcome
    {
        $linked = SocialAccount::query()
            ->withoutGlobalScopes()
            ->with('user')
            ->where('provider', $identity->provider->value)
            ->where('provider_user_id', $identity->id)
            ->first();

        if ($linked !== null && $linked->user !== null) {
            if (($refusal = $this->refusal($linked->user, $tenant)) !== null) {
                return $refusal;
            }

            $linked->fill($this->profile($identity))->save();

            return SocialLoginOutcome::signIn($linked->user->id);
        }

        // ⚠️ Facebook may return no address at all (a phone-number account).
        // No address means no link and no account on a guess: the caller asks
        // for one and has it confirmed by mail first (SLO-252), then resolves
        // again with the confirmed address as a verified one.
        if ($identity->email === null) {
            return SocialLoginOutcome::fail('no_email');
        }

        if (! $identity->emailVerified) {
            return SocialLoginOutcome::fail('email_unverified');
        }

        $existing = User::query()->where('email', $identity->email)->first();

        if ($existing !== null) {
            if (($refusal = $this->refusal($existing, $tenant)) !== null) {
                return $refusal;
            }

            return $this->linkExisting($existing, $identity);
        }

        if ($tenant === null) {
            // The central login page belongs to tenant staff; a new business is
            // registered with the form, not conjured from a Google account.
            return SocialLoginOutcome::fail('no_account');
        }

        if (! $tenant->status->isOperational()) {
            return SocialLoginOutcome::fail('unavailable');
        }

        return $this->registerCustomer($tenant, $identity);
    }

    private function refusal(User $user, ?Tenant $tenant): ?SocialLoginOutcome
    {
        if ($user->isSuperAdmin() || $user->anonymized_at !== null) {
            return SocialLoginOutcome::fail('not_available');
        }

        if ($tenant !== null && $user->tenant_id !== $tenant->id) {
            return SocialLoginOutcome::fail('not_available');
        }

        return null;
    }

    private function linkExisting(User $user, SocialIdentity $identity): SocialLoginOutcome
    {
        $alreadyLinked = SocialAccount::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('provider', $identity->provider->value)
            ->exists();

        // The account already signs in with a DIFFERENT identity at this
        // provider. Replacing it silently would hand the account to whoever
        // controls the new one; unlinking is an explicit act (SLO-252).
        if ($alreadyLinked) {
            return SocialLoginOutcome::fail('not_available');
        }

        try {
            DB::transaction(function () use ($user, $identity): void {
                // ⚠️ Account pre-hijacking. If the address on this account was
                // never proven, whoever created the account may not own the
                // mailbox — and would keep a working password on an account the
                // real owner now uses. The provider has just proven the address,
                // so that password is revoked, and with it every session.
                if ($user->email_verified_at === null) {
                    $user->forceFill([
                        'password' => null,
                        'remember_token' => null,
                        'email_verified_at' => Carbon::now(),
                    ])->save();

                    $this->endSessions($user);
                }

                LinkSocialIdentity::attach($user, $identity);
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel attempt linked the same identity first.
            return SocialLoginOutcome::fail('try_again');
        }

        Log::info('Social identity linked to an existing account', [
            'provider' => $identity->provider->value,
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
        ]);

        return SocialLoginOutcome::signIn($user->id);
    }

    private function registerCustomer(Tenant $tenant, SocialIdentity $identity): SocialLoginOutcome
    {
        $previous = $this->tenants->current();
        $this->tenants->set($tenant);

        try {
            $customer = DB::transaction(function () use ($identity): User {
                $customer = ($this->createCustomer)([
                    'name' => $identity->name ?? strstr((string) $identity->email, '@', true),
                    'email' => $identity->email,
                    'passwordless' => true,
                ]);

                $customer->forceFill(['email_verified_at' => Carbon::now()])->save();

                LinkSocialIdentity::attach($customer, $identity);

                return $customer;
            });
        } catch (UniqueConstraintViolationException) {
            return SocialLoginOutcome::fail('try_again');
        } finally {
            $previous === null ? $this->tenants->forget() : $this->tenants->set($previous);
        }

        Log::info('Customer registered through social login', [
            'provider' => $identity->provider->value,
            'user_id' => $customer->id,
            'tenant_id' => $tenant->id,
        ]);

        return SocialLoginOutcome::signIn($customer->id);
    }

    /** @return array{email: string|null, name: string|null, avatar_url: string|null} */
    private function profile(SocialIdentity $identity): array
    {
        return [
            'email' => $identity->email,
            'name' => $identity->name,
            'avatar_url' => $identity->avatarUrl,
        ];
    }

    private function endSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }
}
