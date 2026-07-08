<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\FindOrCreateCustomer;
use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\PublicBookingRequest;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\Slot;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Public booking page (SLO-30): the {tenant}.slot4u.hu slot-picker. For a
 * time-slot service (duration_based / resource_rental) it shows a week strip and
 * the selected day's free slots (computed by {@see AvailabilityService}), narrowed
 * by the location/staff/room filters. Runs in the public group (identify.tenant →
 * ensure.tenant.active), no auth, throttled. All times are tenant-local for display
 * but carry UTC instants for the booking flow (SLO-31). event_based is a separate
 * view (SLO-91); no_time_slot / quote_request are handled by their own flows.
 */
class BookingController extends Controller
{
    public function __construct(private readonly AvailabilityService $availability) {}

    public function index(Request $request): Response
    {
        $tenant = app(TenantManager::class)->current();
        $timezone = $tenant->timezone;

        $validated = $request->validate([
            'service' => ['nullable', 'integer'],
            'staff' => ['nullable', 'integer'],
            'room' => ['nullable', 'integer'],
            'location' => ['nullable', 'integer'],
            'date' => ['nullable', 'date'],
        ]);

        // validate() does not cast — normalise the query strings to typed values.
        $filters = [
            'staff' => isset($validated['staff']) ? (int) $validated['staff'] : null,
            'room' => isset($validated['room']) ? (int) $validated['room'] : null,
            'location' => isset($validated['location']) ? (int) $validated['location'] : null,
            'date' => $validated['date'] ?? null,
        ];

        $services = Service::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'booking_mode']);

        $requestedServiceId = isset($validated['service']) ? (int) $validated['service'] : null;
        $service = $this->resolveService($requestedServiceId, $services);

        if ($service === null) {
            return Inertia::render('Tenant/Book', [
                'services' => [],
                'service' => null,
                'timezone' => $timezone,
            ]);
        }

        $props = [
            'services' => $services->map(fn (Service $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'booking_mode' => $s->booking_mode->value,
            ])->values(),
            'service' => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'price_minor' => (int) $service->price_minor,
                'currency' => $service->currency,
                'booking_mode' => $service->booking_mode->value,
                'duration_minutes' => $service->duration_minutes,
            ],
            'timezone' => $timezone,
            'filters' => [
                'staff' => $filters['staff'] ?? null,
                'room' => $filters['room'] ?? null,
                'location' => $filters['location'] ?? null,
            ],
        ];

        if ($service->booking_mode->usesTimeSlot()) {
            $props = [...$props, ...$this->slotView($service, $filters, $timezone)];
        }

        return Inertia::render('Tenant/Book', $props);
    }

    /**
     * Submit a public booking for a chosen slot (SLO-31). A guest becomes a
     * customer record (FindOrCreateCustomer); CreateBooking is race-safe and, via
     * source=online, applies the approval/payment gates. A taken slot throws
     * SlotUnavailableException, which self-renders as a back()->withErrors so the
     * picker can prompt a fresh slot. On success we PRG-redirect to the
     * confirmation page.
     */
    public function store(PublicBookingRequest $request, CreateBooking $createBooking, FindOrCreateCustomer $findOrCreateCustomer): RedirectResponse
    {
        $data = $request->validated();

        $service = Service::query()->where('active', true)
            ->with(['staff:id', 'rooms:id'])
            ->findOrFail($data['service_id']);
        abort_unless($service->booking_mode->usesTimeSlot(), 404);

        // Never trust the submitted times: re-validate the slot against live
        // availability (schedule/duration/future), and use the matched slot's own
        // instants — so a crafted POST can't book off-grid or with a wrong length.
        $slot = $this->matchAvailableSlot($service, $data);

        try {
            $customer = $findOrCreateCustomer($data['email'], $data['name'], $data['phone'] ?? null);
        } catch (ValidationException $e) {
            // FindOrCreateCustomer reports on `customer_email`; surface it on the
            // public form's `email` field instead.
            throw ValidationException::withMessages(['email' => $e->validator->errors()->first()]);
        }

        $booking = $createBooking($service, [
            'customer_id' => $customer->id,
            'staff_id' => $slot->staffId,
            'room_id' => $slot->roomId,
            'starts_at' => $slot->start->toDateTimeString(),
            'ends_at' => $slot->end->toDateTimeString(),
            'party_size' => 1,
            'notes' => $data['notes'] ?? null,
            'source' => BookingSource::Online->value,
        ]);

        // Relative redirect keeps us on the current tenant subdomain (the named
        // route is domain-bound and would need the {tenant} param).
        return redirect('/booked/'.$booking->code);
    }

    /**
     * Booking confirmation page (SLO-31), reached by the public code. Route-bound
     * {booking:code} is tenant-scoped (BelongsToTenant → cross-tenant 404). The
     * status drives the message (confirmed / awaiting approval / awaiting payment).
     */
    public function confirmation(string $tenant, Booking $booking): Response
    {
        $booking->load(['service:id,name', 'staff:id,name']);
        $timezone = app(TenantManager::class)->current()->timezone;

        return Inertia::render('Tenant/Booked', [
            'booking' => [
                'code' => $booking->code,
                'service' => $booking->service?->name,
                'staff' => $booking->staff?->name,
                'status' => $booking->status->value,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'starts_local' => $this->localDateTime($booking->starts_at, $timezone),
                'ends_local' => $this->localDateTime($booking->ends_at, $timezone),
            ],
            'timezone' => $timezone,
        ]);
    }

    /**
     * Download the booking as an .ics calendar event (SLO-31, "naptárba mentés").
     * Route-bound {booking:code} is tenant-scoped.
     */
    public function ics(string $tenant, Booking $booking): HttpResponse
    {
        $booking->load('service:id,name');
        $tenantModel = app(TenantManager::class)->current();

        return response($this->buildIcs($booking, $tenantModel), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="foglalas-'.$booking->code.'.ics"',
        ]);
    }

    /**
     * The live availability slot matching the submitted start + resource, or a
     * clean "slot unavailable" error (which self-renders back to the picker). The
     * matched slot's own instants are authoritative — the request times are only a
     * lookup key, never stored verbatim.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function matchAvailableSlot(Service $service, array $data): Slot
    {
        $timezone = app(TenantManager::class)->current()->timezone;
        $start = Carbon::parse($data['starts_at']);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        $roomId = isset($data['room_id']) ? (int) $data['room_id'] : null;

        $slots = $this->availability->slotsForDay(
            $service,
            $start->copy()->timezone($timezone),
            $staffId,
            $roomId,
        );

        foreach ($slots as $slot) {
            if ($slot->start->equalTo($start) && $slot->staffId === $staffId && $slot->roomId === $roomId) {
                return $slot;
            }
        }

        throw ValidationException::withMessages([
            'booking' => __('app.booking.error.slot_unavailable'),
        ]);
    }

    private function localDateTime(?Carbon $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }

    /**
     * A minimal VCALENDAR/VEVENT for the booking; times are UTC (Z-suffixed).
     */
    private function buildIcs(Booking $booking, Tenant $tenant): string
    {
        $start = $booking->starts_at?->copy()->utc()->format('Ymd\THis\Z') ?? '';
        $end = $booking->ends_at?->copy()->utc()->format('Ymd\THis\Z') ?? '';
        $summary = $this->escapeIcs($booking->service->name.' — '.$tenant->name);
        $location = $this->escapeIcs($tenant->name);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//slot4u//booking//HU',
            'BEGIN:VEVENT',
            'UID:'.$booking->code.'@'.$tenant->slug,
            'DTSTART:'.$start,
            'DTEND:'.$end,
            'SUMMARY:'.$summary,
            'LOCATION:'.$location,
            'DESCRIPTION:'.$this->escapeIcs(__('app.tenant.booked.ics_description', ['code' => $booking->code])),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }

    private function escapeIcs(string $value): string
    {
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $value);
    }

    /**
     * The requested active service (with its bookable resources), or the first
     * active service as a default, or null when the tenant has none.
     *
     * @param  Collection<int, Service>  $services
     */
    private function resolveService(?int $requestedId, Collection $services): ?Service
    {
        // Try the requested service first; if it isn't a real active service of
        // THIS tenant (foreign/inactive/unknown id → tenant scope 404s it), fall
        // back to the first active service so the page is never blank.
        $candidateIds = array_values(array_filter([$requestedId, $services->first()?->id]));

        foreach ($candidateIds as $id) {
            $service = Service::query()
                ->where('active', true)
                ->whereKey($id)
                ->with([
                    'staff:id,name',
                    'staff.locations:id,name,active',
                    'rooms:id,name,location_id',
                    'rooms.location:id,name,active',
                ])
                ->first();

            if ($service !== null) {
                return $service;
            }
        }

        return null;
    }

    /**
     * Week strip + the selected day's slots + filter options for a time-slot
     * service.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function slotView(Service $service, array $filters, string $timezone): array
    {
        $today = Carbon::now($timezone)->startOfDay();

        $selected = isset($filters['date'])
            ? Carbon::parse($filters['date'], $timezone)->startOfDay()
            : $today->copy();
        // Never let the picker land on a past day.
        if ($selected->lt($today)) {
            $selected = $today->copy();
        }

        $weekStart = $selected->copy()->startOfWeek(Carbon::MONDAY);
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $week[] = [
                'date' => $day->toDateString(),
                'day' => $day->day,
                'weekday' => $day->isoWeekday(),
                'is_today' => $day->isSameDay($today),
                'is_past' => $day->lt($today),
                'is_selected' => $day->isSameDay($selected),
            ];
        }

        $slots = $this->availability->slotsForDay(
            $service,
            $selected->copy(),
            $filters['staff'] ?? null,
            $filters['room'] ?? null,
            $filters['location'] ?? null,
        );

        return [
            'week' => $week,
            'week_start' => $weekStart->toDateString(),
            'selected_date' => $selected->toDateString(),
            'prev_week' => $weekStart->copy()->subDay()->toDateString(),
            'next_week' => $weekStart->copy()->addDays(7)->toDateString(),
            'is_first_week' => $weekStart->lte($today),
            'slots' => array_map(fn (Slot $slot) => [
                'start' => $slot->start->toIso8601String(),
                'end' => $slot->end->toIso8601String(),
                'staff_id' => $slot->staffId,
                'room_id' => $slot->roomId,
                'time' => $slot->start->copy()->timezone($timezone)->format('H:i'),
            ], $slots),
            'staff_options' => $service->staff->map(fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->name,
                'location_ids' => $staff->locations->pluck('id')->values(),
            ])->values(),
            'room_options' => $service->rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'location_id' => $room->location_id,
            ])->values(),
            'location_options' => $this->locationOptions($service),
        ];
    }

    /**
     * Distinct locations the service is offered at: its staff's locations
     * (duration_based) and its rooms' locations (resource_rental).
     *
     * @return list<array{id: int, name: string}>
     */
    private function locationOptions(Service $service): array
    {
        $locations = $service->staff
            ->flatMap(fn (Staff $staff) => $staff->locations)
            ->concat($service->rooms->map(fn (Room $room) => $room->location)->filter());

        return $locations
            ->filter(fn (?Location $location) => $location !== null && $location->active)
            ->unique('id')
            ->sortBy('name')
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->values()
            ->all();
    }
}
