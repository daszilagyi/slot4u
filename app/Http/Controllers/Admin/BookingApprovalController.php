<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Booking\ApproveBooking;
use App\Actions\Booking\ProposeAlternativeTime;
use App\Actions\Booking\RejectBooking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProposeBookingRequest;
use App\Http\Requests\Admin\RejectBookingRequest;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin decisions on approval-pending bookings (manual_approval, docs/04 §5,
 * SLO-26). Behind auth + ensure.user.tenant + ensure.feature:feature_approval_flow
 * + can:booking.approve (routes/tenant.php). Route-bound {booking} is tenant-scoped
 * (BelongsToTenant → cross-tenant 404). The transition/state logic lives in the
 * action layer; an illegal transition (booking not requested) surfaces as a 422.
 */
class BookingApprovalController extends Controller
{
    // $tenant absorbs the subdomain route parameter (before the bound {booking}).
    public function approve(Request $request, string $tenant, Booking $booking, ApproveBooking $approve): RedirectResponse
    {
        Gate::authorize('approve', $booking);

        $approve($booking, $request->user());

        return back();
    }

    public function reject(RejectBookingRequest $request, string $tenant, Booking $booking, RejectBooking $reject): RedirectResponse
    {
        Gate::authorize('approve', $booking);

        $reject($booking, $request->user(), $request->validated('reason'));

        return back();
    }

    public function propose(ProposeBookingRequest $request, string $tenant, Booking $booking, ProposeAlternativeTime $propose): RedirectResponse
    {
        Gate::authorize('approve', $booking);

        $data = $request->validated();

        $propose($booking, [
            'staff_id' => $data['staff_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
        ], $request->user(), $data['reason'] ?? null);

        return back();
    }
}
