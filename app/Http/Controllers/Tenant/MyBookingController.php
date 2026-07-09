<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Booking\CancelBooking;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\CancelMyBookingRequest;
use App\Models\Booking;
use App\Settings\TenantSettings;
use App\Support\IcsBuilder;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
    public function index(Request $request): Response
    {
        $tenant = app(TenantManager::class)->current();
        $timezone = $tenant->timezone;
        $deadlineHours = TenantSettings::fromArray($tenant->settings)->cancellationDeadlineHours;
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
            'upcoming' => $upcoming->map(fn (Booking $b) => $this->present($b, $timezone, $deadlineHours))->values(),
            'past' => $past->map(fn (Booking $b) => $this->present($b, $timezone, $deadlineHours))->values(),
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
    private function present(Booking $booking, string $timezone, int $deadlineHours): array
    {
        $canCancel = $booking->status->canTransitionTo(BookingStatus::Canceled)
            && ! $booking->isWithinCancellationDeadline($deadlineHours);

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
        ];
    }

    private function localDateTime(?Carbon $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
