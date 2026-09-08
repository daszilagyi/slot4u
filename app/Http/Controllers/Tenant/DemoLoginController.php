<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;

/**
 * One-click sign-in to a demo tenant's admin panel (SLO-192, docs/21 §2.1).
 *
 * The marketing site's "try it live" section links here with a signed URL, so a
 * visitor reaches a working dashboard without an account. That is the strongest
 * thing the landing page can do — a prospect does not buy a feature list, they
 * buy what their own customer will see.
 *
 * ## ⚠️ Why this is safe, in the order the checks run
 *
 * 1. **Signed** (route middleware). The URL carries a signature over the whole
 *    URL including its expiry; edit the tenant in it and the signature fails.
 * 2. **Short-lived** — {@see self::LIFETIME_MINUTES}. A link pasted into a chat
 *    is dead long before anybody scrolls back to it.
 * 3. **Rate limited** (route middleware) — a signed URL is still a URL, and one
 *    that logs somebody in should not be replayable a thousand times a minute.
 * 4. **`is_demo` only.** The load-bearing check: a tenant holding real data has
 *    no path through here at all, whatever a signature says. A 404 rather than
 *    a 403, like every other cross-tenant refusal in this codebase — a wrong
 *    guess should not confirm that something exists.
 * 5. **Never an owner account.** The demo signs in as the tenant's Manager where
 *    there is one, because what a Manager *cannot* reach is half of what the
 *    permission matrix demonstrates (docs/03).
 *
 * Every hit is logged: this is the one route in the application that hands out a
 * session to an anonymous visitor, so "who used it and when" should not depend
 * on anyone remembering to add logging later.
 */
class DemoLoginController extends Controller
{
    /** Long enough to click, short enough that a shared link is useless. */
    public const LIFETIME_MINUTES = 15;

    public function __invoke(TenantManager $tenants): RedirectResponse
    {
        $tenant = $tenants->current();

        // ⚠️ The check everything else rests on. Not `abort_if(! is_demo, 403)`:
        // a 404 says nothing about whether that tenant exists (docs/01 §1).
        abort_unless($tenant?->is_demo === true, 404);

        $user = $this->demoUser($tenant->getKey());

        abort_if($user === null, 404);

        Auth::login($user);

        // ⚠️ Session fixation: a visitor arriving with a session id they chose
        // must not keep it across a privilege change. Laravel does this for its
        // own login form; this route has to do it for itself.
        request()->session()->regenerate();

        Log::info('Demo tenant signed into from the marketing site', [
            'tenant' => $tenant->slug,
            'user_id' => $user->getKey(),
            'ip' => request()->ip(),
        ]);

        return redirect('/dashboard');
    }

    /**
     * The account a visitor is dropped into.
     *
     * Manager first, and deliberately: a Manager runs the diary — bookings,
     * approvals, quotes — but cannot reach settings or billing, and a demo where
     * the visitor is the owner shows a permission model with nothing to
     * demonstrate. Not every persona has one (docs/20 §2), so the tenant admin
     * is the fallback; on a demo tenant that is still only fixtures.
     */
    private function demoUser(int $tenantId): ?User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenantId);

        try {
            $users = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                // Deterministic: the same visitor lands in the same account every
                // time, so a demo that is being presented does not change hands
                // between two clicks.
                ->orderBy('id')
                ->get();

            foreach ([Role::Manager, Role::TenantAdmin] as $role) {
                $match = $users->first(fn (User $user): bool => $user->hasRole($role->value));

                if ($match !== null) {
                    return $match;
                }
            }

            return null;
        } finally {
            $registrar->setPermissionsTeamId(null);
        }
    }
}
