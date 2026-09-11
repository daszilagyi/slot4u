<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateMyPasswordRequest;
use App\Http\Requests\Tenant\UpdateMyProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Members area — the customer's own profile (SLO-96). Lives in the `/my` group
 * behind auth + ensure.user.tenant + ensure.customer. Every action operates on
 * the acting user's own record ($request->user()), so ownership is implicit —
 * there is no id to authorise, and a customer can never reach another account.
 * Email is read-only: it is the global-unique login identity (docs/03).
 */
class MyProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Tenant/My/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function update(UpdateMyProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return back();
    }

    public function updatePassword(UpdateMyPasswordRequest $request): RedirectResponse
    {
        // The `password` attribute is cast to `hashed`, so assignment hashes it.
        $password = (string) $request->validated('password');
        $request->user()->update(['password' => $password]);

        // Every other session ends on its next request because its stored
        // password hash no longer matches (AuthenticateSession, SLO-99). This
        // call adds the one thing that does not follow from the hash alone: it
        // re-issues THIS device's remember-me cookie, which still carries the
        // old hash and would otherwise sign this device out too, the first time
        // its session expires.
        Auth::logoutOtherDevices($password);

        return back();
    }
}
