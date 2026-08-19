<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Strips one `users` row of everything that identifies or admits a person
 * (SLO-159, SLO-160).
 *
 * Shared by the two callers that erase an account — the single-customer erasure
 * ({@see AnonymizeCustomer}) and the archived-tenant purge ({@see PurgeTenant})
 * — precisely because they must never drift apart: a new personal column on
 * `users` has to be forgotten by both on the day it lands, and one place to
 * change is the only way that holds.
 *
 * The row is overwritten, never deleted. Deleting it would cascade into the
 * bookings that carry the tenant's turnover, and turnover is the commission
 * base (docs/10 §3.1) — an erasure must not rewrite what a tenant owes.
 */
final class AnonymizeUserProfile
{
    /**
     * Overwrite `$user`'s identity, naming them with `$labelKey` resolved in the
     * tenant's locale. Returns false when the account was already anonymised, so
     * callers can stay idempotent without re-reading the row.
     *
     * - `email` becomes a per-user address in the reserved `.invalid` TLD
     *   (RFC 2606), which is guaranteed undeliverable — a real-looking synthetic
     *   address could collide with someone's actual mailbox;
     * - `password` becomes a random unknown-to-anyone value rather than null,
     *   because a null hash is a login attempt away from an unexpected outcome;
     * - the session rows go, so the erasure logs the person out everywhere
     *   instead of leaving a live session on an erased account.
     */
    public function erase(User $user, Tenant $tenant, string $labelKey): bool
    {
        if ($user->anonymized_at !== null) {
            return false;
        }

        $this->deleteSessions($user);

        $user->forceFill([
            'name' => $this->placeholder($labelKey, $tenant),
            'email' => 'anonymized-'.$user->id.'@invalid',
            'phone' => null,
            'email_verified_at' => null,
            'password' => Str::random(64),
            'remember_token' => null,
            'anonymized_at' => Carbon::now(),
        ])->save();

        return true;
    }

    private function deleteSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->delete();
    }

    /**
     * A user-visible replacement string, resolved in the *tenant's* locale
     * rather than the request's.
     *
     * The value is written into the database and then rendered by every admin
     * screen that lists people, so it has to read as a sentence, not as a token
     * — and retrofitting a localised label onto every one of those screens would
     * be a much larger change with one screen guaranteed to be missed. Taking it
     * from the lang file keeps the "no hardcoded UI string" rule.
     */
    public function placeholder(string $key, Tenant $tenant): string
    {
        return (string) trans('app.privacy.'.$key, [], $tenant->locale);
    }
}
