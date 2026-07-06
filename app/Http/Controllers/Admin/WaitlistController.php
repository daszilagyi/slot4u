<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Waitlist\JoinWaitlist;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WaitlistRequest;
use App\Models\Event;
use App\Models\WaitlistEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-side waitlist join (SLO-25). Lives behind auth + ensure.user.tenant +
 * can:booking.create (routes/tenant.php) — the flow an admin drives when an event
 * is full (the M4 public flow reuses {@see JoinWaitlist}). The full list/UI is
 * SLO-28; this is the write path. Guard failures surface as a 422.
 */
class WaitlistController extends Controller
{
    // $tenant absorbs the subdomain route parameter.
    public function store(WaitlistRequest $request, JoinWaitlist $joinWaitlist): RedirectResponse
    {
        Gate::authorize('create', WaitlistEntry::class);

        $data = $request->validated();

        // Resolve tenant-scoped (BelongsToTenant → 404 on a cross-tenant id).
        $event = Event::query()->findOrFail($data['event_id']);

        $joinWaitlist($event, (int) $data['customer_id'], (int) $data['party_size']);

        return back();
    }
}
