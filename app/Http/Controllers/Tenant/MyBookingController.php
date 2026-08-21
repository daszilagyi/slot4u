<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\BuildsSlotView;
use App\Http\Requests\Tenant\CancelMyBookingRequest;
use App\Http\Requests\Tenant\RescheduleMyBookingRequest;
use App\Models\Booking;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\OnlineCancellation;
use App\Settings\TenantSettings;
use App\Support\IcsBuilder;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Members area — a logged-in customer's own bookings (SLO-33). Lives in the
 * `/my` group behind auth + ensure.user.tenant + ensure.customer (NOT the admin
 * `ensure.staff`). Every booking is scoped to the customer: the list filters by
 * `customer_id`, and per-record routes 404 a booking that isn't theirs (hidden
 * existence, like a cross-tenant id — mirrors the admin ownership guard). Online
 * cancellation goes through {@see CancelBooking} with `online: true`, so the
 * tenant's cancellation deadline is enforced in the action, not here.
 */
class MyBookingController extends Controller
{
    use BuildsSlotView;

    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): Response
    {
        $tenant = app(TenantManager::class)->current();
        $timezone = $tenant->timezone;
        $settings = TenantSettings::fromArray($tenant->settings);
        $now = Carbon::now();

        /** @var Collection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->where('customer_id', $request->user()->getKey())
            ->with(['service:id,name', 'staff:id,name'])
            ->get();

        // A timeless (no_time_slot) booking has no instant to compare — treat it
        // as upcoming until it reaches a terminal status.
        $isUpcoming = fn (Booking $b): bool => $b->starts_at === null || $b->starts_at->gte($now);

        $upcoming = $bookings
            ->filter($isUpcoming)
            ->sortBy(fn (Booking $b) => $b->starts_at?->getTimestamp() ?? PHP_INT_MAX);

        $past = $bookings
            ->reject($isUpcoming)
            ->sortByDesc(fn (Booking $b) => $b->starts_at?->getTimestamp() ?? 0);

        return Inertia::render('Tenant/My/Bookings', [
            'upcoming' => $upcoming->map(fn (Booking $b) => $this->present($b, $timezone, $settings))->values(),
            'past' => $past->map(fn (Booking $b) => $this->present($b, $timezone, $settings))->values(),
            'timezone' => $timezone,
        ]);
    }

    /**
     * Cancel one of the customer's own bookings online. The deadline check lives
     * in CancelBooking (online: true) and surfaces as a validation error on the
     * `cancel` key, which Inertia renders back on the page.
     */
    public function cancel(CancelMyBookingRequest $request, string $tenant, Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        // Ownership via BookingPolicy::cancelOwn, mapped to 404 (hidden existence,
        // not 403) so another customer's booking is indistinguishable from a
        // non-existent one — mirrors the admin ownership guard.
        abort_unless($request->user()->can('cancelOwn', $booking), 404);

        $cancelBooking($booking, $request->user(), $request->validated('reason'), online: true);

        return redirect('/my/bookings');
    }

    /**
     * Slot re-picker for rescheduling one of the customer's own time-slot bookings
     * (SLO-97). Ownership 404 (rescheduleOwn); only a time-slot mode with a live,
     * non-terminal booking can be moved. Reuses the public slot-picker view.
     */
    public function rescheduleForm(Request $request, string $tenant, Booking $booking): Response
    {
        abort_unless($request->user()->can('rescheduleOwn', $booking), 404);

        // The service loads with all columns (AvailabilityService needs its tenant +
        // settings; the picker uses its bounds) — only the nested resources are
        // column-restricted for the filter options.
        $booking->load(['service.staff:id,name', 'service.staff.locations:id,name,active', 'service.rooms:id,name,location_id', 'service.rooms.location:id,name,active']);
        $service = $booking->service;
        // Only a time-slot booking that can still be canceled (non-terminal) is movable.
        abort_unless(
            $service !== null
                && $service->booking_mode->usesTimeSlot()
                && $booking->status->canTransitionTo(BookingStatus::Canceled),
            404,
        );

        $tenantModel = app(TenantManager::class)->current();
        $timezone = $tenantModel->timezone;

        // Prefill the picker with the booking's current staff/room + a starting date.
        $filters = ['staff' => $booking->staff_id, 'room' => $booking->room_id, 'location' => null, 'date' => $request->query('date')];

        return Inertia::render('Tenant/My/Reschedule', [
            ...$this->slotView($service, $filters, $timezone),
            'booking' => [
                'id' => $booking->id,
                'code' => $booking->code,
                'service' => $service->name,
                'booking_mode' => $service->booking_mode->value,
                'duration_minutes' => $service->duration_minutes,
                'min_duration_minutes' => $service->rentalDurationBounds()['min'] ?? null,
                'max_duration_minutes' => $service->rentalDurationBounds()['max'] ?? null,
                'current_local' => $this->localDateTime($booking->starts_at, $timezone),
            ],
            'timezone' => $timezone,
            'filters' => ['staff' => $booking->staff_id, 'room' => $booking->room_id, 'location' => null],
        ]);
    }

    /**
     * Apply the reschedule (SLO-97). The new slot is re-validated against live
     * availability; RescheduleBooking cancels the old + creates the new in one
     * transaction with online: true, so the tenant cancellation deadline is enforced
     * (a within-deadline attempt surfaces on the `cancel` key and rolls back).
     */
    public function reschedule(RescheduleMyBookingRequest $request, string $tenant, Booking $booking, RescheduleBooking $reschedule): RedirectResponse
    {
        abort_unless($request->user()->can('rescheduleOwn', $booking), 404);

        // The service loads with all columns: matchAvailableSlot / AvailabilityService
        // read its tenant + settings, and CreateBooking snapshots its price/config.
        $booking->load(['service.staff:id', 'service.rooms:id']);
        $service = $booking->service;
        // Mirror rescheduleForm's guard: only a time-slot, still-cancelable booking
        // is movable. The state machine would also reject a terminal booking (clean
        // 422), but guarding here keeps the 404 consistent with the form (SLO-97).
        abort_unless(
            $service !== null
                && $service->booking_mode->usesTimeSlot()
                && $booking->status->canTransitionTo(BookingStatus::Canceled),
            404,
        );

        $data = $request->validated();
        $slot = $this->matchAvailableSlot($service, [...$data, 'service_id' => $service->id]);

        try {
            $reschedule($booking, $service, [
                'customer_id' => $booking->customer_id,
                'staff_id' => $slot->staffId,
                'room_id' => $slot->roomId,
                'starts_at' => $slot->start->toDateTimeString(),
                'ends_at' => $slot->end->toDateTimeString(),
                'party_size' => 1,
                'source' => BookingSource::Online->value,
            ], $request->user(), online: true);
        } catch (SlotUnavailableException) {
            throw ValidationException::withMessages([
                'booking' => __('app.booking.error.slot_unavailable'),
            ]);
        }

        return redirect('/my/bookings');
    }

    /**
     * Download one of the customer's own bookings as an .ics calendar event.
     */
    public function ics(Request $request, string $tenant, Booking $booking): HttpResponse
    {
        abort_unless($request->user()->can('viewOwn', $booking), 404);

        $booking->load('service:id,name');
        $tenant = app(TenantManager::class)->current();

        return response(IcsBuilder::build($booking, $tenant), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="foglalas-'.$booking->code.'.ics"',
        ]);
    }

    /**
     * The customer-facing shape of a booking. `can_cancel` drives the button
     * state: cancelable only while non-terminal AND outside the cancellation
     * deadline (mirrors the CancelBooking online guard).
     *
     * @return array<string, mixed>
     */
    private function present(Booking $booking, string $timezone, TenantSettings $settings): array
    {
        // The same rule the endpoint enforces (SLO-129), including the tenant's
        // "online cancellation off" switch.
        $canCancel = OnlineCancellation::allowed($booking, $settings);

        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'service' => $booking->service?->name,
            'staff' => $booking->staff?->name,
            'status' => $booking->status->value,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'starts_local' => $this->localDateTime($booking->starts_at, $timezone),
            'ends_local' => $this->localDateTime($booking->ends_at, $timezone),
            'can_cancel' => $canCancel,
            // A reschedule moves a time-slot booking to a new slot (SLO-97); offered
            // only for a movable (time-slot, non-terminal) booking still within the
            // cancellation policy — same online guard as can_cancel.
            'can_reschedule' => $booking->booking_mode->usesTimeSlot() && $canCancel,
        ];
    }
}
