<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Booking\CompleteBooking;
use App\Actions\Booking\CreateBooking;
use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookingRequest;
use App\Models\Booking;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-side booking creation (SLO-24). Lives behind auth + ensure.user.tenant +
 * can:booking.create (routes/tenant.php). The full booking list/UI is SLO-28; this
 * endpoint is the write path an admin (or the M4 public flow) drives. Slot/capacity
 * race protection + status logic live in {@see CreateBooking}; a taken slot throws
 * SlotUnavailableException (rendered as a 422).
 */
class BookingController extends Controller
{
    // $tenant absorbs the subdomain route parameter.
    public function store(BookingRequest $request, CreateBooking $createBooking): RedirectResponse
    {
        Gate::authorize('create', Booking::class);

        $data = $request->validated();

        // The route-bound {service} would be cleaner, but the service id is part of
        // the booking payload; resolve it tenant-scoped (BelongsToTenant → 404).
        $service = Service::query()->findOrFail($data['service_id']);

        // Admin origin: source=admin relaxes the approval/payment gates.
        $data['source'] = BookingSource::Admin->value;

        $createBooking($service, $data, $request->user());

        return back();
    }

    /**
     * Fulfil a no_time_slot booking (manual/downloadable closure, docs/04 §1,
     * SLO-27): confirmed → completed. Gated by booking.edit. Route-bound {booking}
     * is tenant-scoped; a wrong-mode booking surfaces as a 422.
     */
    public function complete(Request $request, string $tenant, Booking $booking, CompleteBooking $completeBooking): RedirectResponse
    {
        Gate::authorize('update', $booking);

        $completeBooking($booking, $request->user());

        return back();
    }
}
