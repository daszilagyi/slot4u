<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Staff\CreateStaff;
use App\Actions\Staff\DeleteStaff;
use App\Actions\Staff\UpdateStaff;
use App\Enums\PlanLimitKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\Location;
use App\Models\Staff;
use App\Services\Plan\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant staff management (SLO-17). Lives behind
 * auth + ensure.user.tenant + can:staff.manage (routes/tenant.php); business
 * rules (plan limit, invitation, delete protection) live in the Action classes.
 */
class StaffController extends Controller
{
    public function index(PlanLimitService $limits): Response
    {
        Gate::authorize('viewAny', Staff::class);

        // Eager-load the linked user + assigned locations to keep the render
        // (invite status + location chips) N+1-free.
        $staff = Staff::query()
            ->with(['user:id,email', 'locations:id'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Staff/Index', [
            'staff' => $staff->map(fn (Staff $member) => $this->staffData($member))->values(),
            'locations' => Location::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'limits' => [
                'employees' => [
                    'used' => $staff->count(),
                    'max' => $limits->limitFor(PlanLimitKey::MaxEmployees),
                ],
            ],
        ]);
    }

    public function store(StaffRequest $request, CreateStaff $createStaff): RedirectResponse
    {
        Gate::authorize('create', Staff::class);

        $createStaff($request->validated());

        return back();
    }

    // $tenant is the subdomain route parameter (docs/01); it is consumed here so
    // the {staff} path parameter binds by name to the Staff model.
    public function update(StaffRequest $request, string $tenant, Staff $staff, UpdateStaff $updateStaff): RedirectResponse
    {
        Gate::authorize('update', $staff);

        $updateStaff($staff, $request->validated());

        return back();
    }

    public function destroy(string $tenant, Staff $staff, DeleteStaff $deleteStaff): RedirectResponse
    {
        Gate::authorize('delete', $staff);

        $deleteStaff($staff);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function staffData(Staff $member): array
    {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'title' => $member->title,
            'bio' => $member->bio,
            'photo' => $member->photo,
            'color' => $member->color,
            'active' => $member->active,
            'user' => $member->user === null ? null : [
                'email' => $member->user->email,
            ],
            'location_ids' => $member->locations->pluck('id')->values(),
        ];
    }
}
