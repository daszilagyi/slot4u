<?php

namespace App\Support;

use App\Http\Responses\Concerns\RedirectsToUserHome;
use App\Models\User;

/**
 * Where a signed-in user's own home is, as an absolute URL: the admin panel for
 * a super-admin, the tenant dashboard for staff, the members area for a customer
 * (SLO-33) — `/dashboard` would 403 them at `ensure.staff`.
 *
 * One answer for every place that sends a user home. The sign-in redirects
 * ({@see RedirectsToUserHome}) and the marketing
 * header's "Vezérlőpult" button used to work it out separately, and the button
 * got it wrong twice: nothing for a super-admin (it fell back to `/`, the page
 * already open) and `/dashboard` for a customer (SLO-249).
 *
 * Null only for a broken record — a non-super-admin without a tenant.
 */
final class UserHomeUrl
{
    public static function for(User $user, string $scheme): ?string
    {
        $central = config('tenancy.central_domain');

        if ($user->isSuperAdmin()) {
            return $scheme.'://'.config('tenancy.admin_subdomain').'.'.$central.'/';
        }

        $tenant = $user->tenant;

        if ($tenant === null) {
            return null;
        }

        $path = $user->isStaff() ? '/dashboard' : '/my/bookings';

        return $scheme.'://'.$tenant->slug.'.'.$central.$path;
    }
}
