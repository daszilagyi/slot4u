<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\ProposeAlternativeTime;
use App\Actions\Booking\RejectBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProposeBookingRequest;
use App\Http\Requests\Admin\RejectBookingRequest;
use App\Models\Booking;
use App\Support\BookingVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin decisions on approval-pending bookings (manual_approval, docs/04 §5,
 * SLO-26). Behind auth + ensure.user.tenant + ensure.feature:feature_approval_flow
 * + can:booking.approve (routes/tenant.php). Route-bound {booking} is tenant-scoped
 * (BelongsToTenant → cross-tenant 404). The transition/state logic lives in the
 * action layer; an illegal transition (booking not requested) surfaces as a 422.
 *
 * A booking outside the actor's visibility ({@see BookingVisibility}: an employee
 * sees only their own) surfaces as a 404 before the policy runs — the same hidden-
 * existence rule the rest of the booking surface follows, so a 403 can never reveal
 * that a colleague's booking exists.
 */
class BookingApprovalController extends Controller
{
    // $tenant absorbs the subdomain route parameter (before the bound {booking}).
    public function approve(Request $request, string $tenant, Booking $booking, ApproveBooking $approve): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('approve', $booking);

        $approve($booking, $actor);

        return back();
    }

    public function reject(RejectBookingRequest $request, string $tenant, Booking $booking, RejectBooking $reject): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('approve', $booking);

        $reject($booking, $actor, $request->validated('reason'));

        return back();
    }

    public function propose(ProposeBookingRequest $request, string $tenant, Booking $booking, ProposeAlternativeTime $propose): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('approve', $booking);

        $data = $request->validated();

        $propose($booking, [
            // Falling back to the booking's own resource, not to null: the form only
            // has to name a resource when the offer moves it. Sending null here would
            // silently strip the staff/room off the proposal (the reschedule path has
            // always defaulted this way).
            'staff_id' => $data['staff_id'] ?? $booking->staff_id,
            'room_id' => $data['room_id'] ?? $booking->room_id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
        ], $actor, $data['reason'] ?? null);

        return back();
    }
}
