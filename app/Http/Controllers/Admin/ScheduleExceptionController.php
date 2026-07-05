<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Schedule\CreateScheduleException;
use App\Actions\Schedule\DeleteScheduleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduleExceptionRequest;
use App\Models\ScheduleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Schedule exception management (SLO-19): time off / extra opening on a date.
 * Gated by can:schedule.manage (routes/tenant.php); route-bound exceptions are
 * tenant-scoped (BelongsToTenant global scope → cross-tenant 404).
 */
class ScheduleExceptionController extends Controller
{
    public function store(ScheduleExceptionRequest $request, CreateScheduleException $createException): RedirectResponse
    {
        Gate::authorize('create', ScheduleException::class);

        $createException($request->validated());

        return back();
    }

    public function destroy(string $tenant, ScheduleException $exception, DeleteScheduleException $deleteException): RedirectResponse
    {
        Gate::authorize('delete', $exception);

        $deleteException($exception);

        return back();
    }
}
