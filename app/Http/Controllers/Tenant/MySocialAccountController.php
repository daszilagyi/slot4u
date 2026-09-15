<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UnlinkSocialAccountRequest;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\Platform\SocialAccountChangedNotification;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Members area — removing a linked Google / Facebook sign-in (SLO-252). The
 * route-bound account is tenant-scoped (BelongsToTenant → another tenant's id
 * 404s), and the policy 404s another customer's link in the same tenant.
 */
class MySocialAccountController extends Controller
{
    public function destroy(UnlinkSocialAccountRequest $request, string $tenant, SocialAccount $socialAccount): RedirectResponse
    {
        $user = $request->user();

        // The request already refused the last way in; this repeats it under a
        // lock on the user row, because two unlinks sent at once (Google and
        // Facebook) would each see "the other one remains" and leave none.
        DB::transaction(function () use ($user, $socialAccount): void {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $others = SocialAccount::query()
                ->withoutGlobalScopes()
                ->where('user_id', $user->id)
                ->whereKeyNot($socialAccount->getKey())
                ->exists();

            if (! $user->hasPassword() && ! $others) {
                throw ValidationException::withMessages([
                    'social' => __('app.auth.social.errors.last_sign_in_method'),
                ]);
            }

            $socialAccount->delete();
        });

        Log::info('Social identity unlinked from the profile', [
            'provider' => $socialAccount->provider->value,
            'user_id' => $socialAccount->user_id,
            'tenant_id' => $socialAccount->tenant_id,
        ]);

        $user->notify(new SocialAccountChangedNotification(
            (string) (app(TenantManager::class)->current()->name ?? config('app.name')),
            $socialAccount->provider->label(),
            linked: false,
        ));

        return back()->with('status', __('app.tenant.my.profile.social_unlinked'));
    }
}
