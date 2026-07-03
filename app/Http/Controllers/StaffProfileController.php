<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StaffProfileRequest;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee self-service profile (SLO-17). Any tenant user reaches this page; a
 * user linked to a staff record may edit their own profile (docs/03 "employee a
 * saját profilját szerkesztheti"). Users without a linked staff record (e.g. a
 * tenant-admin) see an informational empty state. Not gated by staff.manage —
 * ownership is enforced by the StaffPolicy `update` ability.
 */
class StaffProfileController extends Controller
{
    public function edit(): Response
    {
        $staff = $this->currentStaff();

        return Inertia::render('Admin/Profile/Edit', [
            'staff' => $staff === null ? null : [
                'id' => $staff->id,
                'name' => $staff->name,
                'title' => $staff->title,
                'bio' => $staff->bio,
                'color' => $staff->color,
            ],
        ]);
    }

    public function update(StaffProfileRequest $request): RedirectResponse
    {
        $staff = $this->currentStaff();

        abort_if($staff === null, 404);

        Gate::authorize('update', $staff);

        $staff->update($request->validated());

        return back();
    }

    /**
     * The staff record linked to the acting user, if any. The BelongsToTenant
     * scope keeps this within the current tenant.
     */
    private function currentStaff(): ?Staff
    {
        $userId = request()->user()?->getKey();

        // Guard against a null user id degrading into whereNull('user_id'),
        // which would match a login-less staff resource. Unreachable behind auth,
        // but keeps the query unambiguous.
        if ($userId === null) {
            return null;
        }

        return Staff::query()->where('user_id', $userId)->first();
    }
}
