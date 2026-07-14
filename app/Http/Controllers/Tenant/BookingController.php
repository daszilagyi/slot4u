<?php

namespace App\Http\Controllers\Tenant;

use App\Actions\Booking\CreateBooking;
use App\Actions\Customer\FindOrCreateCustomer;
use App\Actions\Quote\CreateQuoteRequest;
use App\Actions\Quote\PostQuoteMessage;
use App\Actions\Waitlist\JoinWaitlist;
use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\EventStatus;
use App\Enums\Feature;
use App\Exceptions\SlotUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\Concerns\BuildsSlotView;
use App\Http\Requests\Tenant\PublicBookingRequest;
use App\Http\Requests\Tenant\PublicEventBookingRequest;
use App\Http\Requests\Tenant\PublicOrderRequest;
use App\Http\Requests\Tenant\PublicQuoteRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Service;
use App\Services\Booking\AvailabilityService;
use App\Services\Feature\FeatureResolver;
use App\Support\IcsBuilder;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    use BuildsSlotView;

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
                // Free-range rental bounds (SLO-92): drive the duration picker; null
                // for a fixed-duration rental and every other mode.
                'min_duration_minutes' => $service->rentalDurationBounds()['min'] ?? null,
                'max_duration_minutes' => $service->rentalDurationBounds()['max'] ?? null,
                // Drives the "what happens after you order" note on the
                // no_time_slot form (docs/04 §1); null for every other mode.
                'fulfillment_type' => $service->booking_mode === BookingMode::NoTimeSlot
                    ? $service->fulfillmentType()
                    : null,
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
        } elseif ($service->booking_mode === BookingMode::EventBased) {
            $props = [...$props, ...$this->eventView($service, $timezone)];
        } elseif ($service->booking_mode === BookingMode::QuoteRequest) {
            $props = [...$props, ...$this->quoteView($service)];
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

        $customer = $this->findGuest($findOrCreateCustomer, $data);

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
     * Submit a public no_time_slot order (SLO-101). The mode has no slot and no
     * resource to contend for, so there is nothing to re-validate against live
     * availability — only the mode itself, which the route-independent
     * PublicOrderRequest cannot enforce. CreateBooking applies the approval/payment
     * gates via source=online and completes a confirmed digital order on the spot
     * (docs/04 §1); manual/downloadable stay confirmed for an admin to close.
     */
    public function storeOrder(PublicOrderRequest $request, CreateBooking $createBooking, FindOrCreateCustomer $findOrCreateCustomer): RedirectResponse
    {
        $data = $request->validated();

        $service = Service::query()->where('active', true)->findOrFail($data['service_id']);
        // Only this mode orders without a time. Anything else (a time-slot service,
        // an event, or a quote_request that must walk its own lifecycle) is not a
        // valid target for this endpoint.
        abort_unless($service->booking_mode === BookingMode::NoTimeSlot, 404);

        $customer = $this->findGuest($findOrCreateCustomer, $data);

        $booking = $createBooking($service, [
            'customer_id' => $customer->id,
            'party_size' => 1,
            'notes' => $data['notes'] ?? null,
            'source' => BookingSource::Online->value,
        ]);

        return redirect('/booked/'.$booking->code);
    }

    /**
     * Submit a public quote request (SLO-102, docs/04 §6). This mode never books:
     * it opens a `new` quote request whose `parameters` hold the answers to the
     * service's own form, and the tenant works it up into a price. The guest's
     * free-text message becomes the first message of the request's conversation
     * thread, so an admin answers in one place. The route sits behind
     * ensure.feature:feature_quote_request; the mode is re-checked here because a
     * route-independent FormRequest cannot enforce it.
     */
    public function storeQuote(PublicQuoteRequest $request, CreateQuoteRequest $createQuoteRequest, PostQuoteMessage $postQuoteMessage, FindOrCreateCustomer $findOrCreateCustomer): RedirectResponse
    {
        $data = $request->validated();

        $service = Service::query()->where('active', true)->findOrFail($data['service_id']);
        abort_unless($service->booking_mode === BookingMode::QuoteRequest, 404);

        $customer = $this->findGuest($findOrCreateCustomer, $data);

        $quoteRequest = $createQuoteRequest($service, [
            'customer_id' => $customer->id,
            'parameters' => $this->quoteParameters($service, $data['fields'] ?? []),
        ], $customer);

        $message = trim((string) ($data['notes'] ?? ''));
        if ($message !== '') {
            $postQuoteMessage($quoteRequest, $message, $customer);
        }

        return redirect('/quote-sent')->with('quote', ['service' => $service->name]);
    }

    /**
     * Quote request confirmation (SLO-102), reached by PRG redirect from
     * storeQuote. A direct visit (no flash) falls back to the tenant home, like
     * the waitlist confirmation.
     */
    public function quoteSent(Request $request): Response|RedirectResponse
    {
        $quote = $request->session()->get('quote');

        if (! is_array($quote)) {
            return redirect('/');
        }

        return Inertia::render('Tenant/QuoteSent', ['quote' => $quote]);
    }

    /**
     * Pair the positional answers with the service's field labels (validated to
     * be the same length by PublicQuoteRequest). Only the service's own labels
     * ever become `parameters` keys.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array<string, string>|null
     */
    private function quoteParameters(Service $service, array $answers): ?array
    {
        $labels = $service->quoteFields();

        if ($labels === []) {
            return null;
        }

        $values = array_map(fn (mixed $value): string => (string) $value, array_values($answers));

        return array_combine($labels, $values);
    }

    /**
     * Sign up for an event_based occurrence (SLO-100). The event is route-bound
     * (tenant-scoped → cross-tenant 404); CreateBooking claims capacity atomically
     * for the chosen party_size and applies the approval/payment gates via
     * source=online. If the event filled up between the view and the submit, the
     * atomic claim throws and we surface it on the form (the guest can then join
     * the waitlist if it is offered).
     */
    public function storeEvent(PublicEventBookingRequest $request, string $tenant, Event $event, CreateBooking $createBooking, FindOrCreateCustomer $findOrCreateCustomer): RedirectResponse
    {
        $service = $this->bookableEventService($event);
        $data = $request->validated();
        $customer = $this->findGuest($findOrCreateCustomer, $data);

        try {
            $booking = $createBooking($service, [
                'customer_id' => $customer->id,
                'event_id' => $event->id,
                'starts_at' => $event->starts_at->toDateTimeString(),
                'ends_at' => $event->ends_at->toDateTimeString(),
                'party_size' => (int) $data['party_size'],
                'notes' => $data['notes'] ?? null,
                'source' => BookingSource::Online->value,
            ]);
        } catch (SlotUnavailableException) {
            throw ValidationException::withMessages([
                'party_size' => __('app.booking.error.event_full'),
            ]);
        }

        return redirect('/booked/'.$booking->code);
    }

    /**
     * Join a full event's FIFO waitlist as a guest (SLO-100). Offered only when
     * the per-event flag AND the tenant feature are on; JoinWaitlist enforces the
     * full-check + no-duplicate rules and assigns a gap-free position, which the
     * confirmation page shows.
     */
    public function storeWaitlist(PublicEventBookingRequest $request, string $tenant, Event $event, JoinWaitlist $joinWaitlist, FindOrCreateCustomer $findOrCreateCustomer): RedirectResponse
    {
        $service = $this->bookableEventService($event);
        $tenantModel = app(TenantManager::class)->current();

        abort_unless(
            $event->waitlist_enabled && app(FeatureResolver::class)->enabled($tenantModel, Feature::Waitlist),
            404,
        );

        $data = $request->validated();
        $customer = $this->findGuest($findOrCreateCustomer, $data);
        $entry = $joinWaitlist($event, $customer->id, (int) $data['party_size']);

        return redirect('/waitlisted')->with('waitlist', [
            'position' => $entry->position,
            'service' => $service->name,
            'starts_local' => $this->localDateTime($event->starts_at, $tenantModel->timezone),
        ]);
    }

    /**
     * Waitlist join confirmation (SLO-100), reached by PRG redirect from
     * storeWaitlist with the position flashed. A direct visit (no flash) falls
     * back to the tenant home.
     */
    public function waitlisted(Request $request): Response|RedirectResponse
    {
        $waitlist = $request->session()->get('waitlist');

        if (! is_array($waitlist)) {
            return redirect('/');
        }

        return Inertia::render('Tenant/Waitlisted', ['waitlist' => $waitlist]);
    }

    /**
     * The service behind a bookable event, or 404 if the event is not a currently
     * bookable event_based occurrence (wrong mode / inactive / canceled / past).
     */
    private function bookableEventService(Event $event): Service
    {
        $event->loadMissing('service');
        $service = $event->service;

        abort_unless(
            $service !== null
                && $service->active
                && $service->booking_mode === BookingMode::EventBased
                && $event->status === EventStatus::Scheduled
                && $event->starts_at->isFuture(),
            404,
        );

        return $service;
    }

    /**
     * Resolve the guest into a customer (FindOrCreateCustomer), surfacing the
     * "email belongs to another account" error on the public form's email field.
     *
     * @param  array<string, mixed>  $data
     */
    private function findGuest(FindOrCreateCustomer $findOrCreateCustomer, array $data): Customer
    {
        try {
            return $findOrCreateCustomer($data['email'], $data['name'], $data['phone'] ?? null);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['email' => $e->validator->errors()->first()]);
        }
    }

    /**
     * Booking confirmation page (SLO-31), reached by the public code. Route-bound
     * {booking:code} is tenant-scoped (BelongsToTenant → cross-tenant 404). The
     * status drives the message (confirmed / awaiting approval / awaiting payment).
     */
    public function confirmation(string $tenant, Booking $booking): Response
    {
        $booking->load(['service:id,name,settings,booking_mode', 'staff:id,name']);
        $timezone = app(TenantManager::class)->current()->timezone;

        $service = $booking->service;
        // Deliver the digital content link only once the order is completed and the
        // service is a digital no_time_slot (docs/04 §1, SLO-105). The public code is the
        // access key, so anyone with the confirmation URL may see it.
        $contentUrl = ($booking->status === BookingStatus::Completed
            && $service !== null
            && $service->booking_mode === BookingMode::NoTimeSlot
            && $service->fulfillmentType() === 'digital')
            ? $service->contentUrl()
            : null;

        return Inertia::render('Tenant/Booked', [
            'booking' => [
                'code' => $booking->code,
                'service' => $booking->service?->name,
                'staff' => $booking->staff?->name,
                'status' => $booking->status->value,
                'starts_at' => $booking->starts_at?->toIso8601String(),
                'starts_local' => $this->localDateTime($booking->starts_at, $timezone),
                'ends_local' => $this->localDateTime($booking->ends_at, $timezone),
                'content_url' => $contentUrl,
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
        // A no_time_slot order has no start/end, so there is no calendar event to
        // emit — without this the builder would write an empty DTSTART/DTEND and
        // hand the guest a malformed .ics. The confirmation page hides the button
        // for such a booking; this closes the direct-URL path (SLO-101).
        abort_if($booking->starts_at === null || $booking->ends_at === null, 404);

        $booking->load('service:id,name');
        $tenantModel = app(TenantManager::class)->current();

        return response(IcsBuilder::build($booking, $tenantModel), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="foglalas-'.$booking->code.'.ics"',
        ]);
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
     * The upcoming announced occurrences of an event_based service (SLO-91):
     * scheduled events starting in the future, ordered by start, each with its
     * remaining capacity / full state and whether a waitlist can be joined (the
     * per-event flag AND the tenant feature). The actual sign-up / waitlist join
     * is SLO-32. Tenant-isolated via the Event BelongsToTenant global scope.
     *
     * @return array{events: array<int, array<string, mixed>>}
     */
    private function eventView(Service $service, string $timezone): array
    {
        $waitlistFeature = app(FeatureResolver::class)->enabled(
            app(TenantManager::class)->current(),
            Feature::Waitlist,
        );

        $events = Event::query()
            ->where('service_id', $service->id)
            ->where('status', EventStatus::Scheduled)
            ->where('starts_at', '>', now())
            ->with(['staff:id,name', 'room:id,name'])
            ->orderBy('starts_at')
            ->get();

        return [
            'events' => $events->map(function (Event $event) use ($timezone, $waitlistFeature) {
                $remaining = max(0, $event->capacity - $event->booked_count);
                $isFull = $remaining === 0;

                return [
                    'id' => $event->id,
                    'starts_local' => $this->localDateTime($event->starts_at, $timezone),
                    'ends_time' => $event->ends_at->copy()->timezone($timezone)->format('H:i'),
                    'staff' => $event->staff?->name,
                    'room' => $event->room?->name,
                    'capacity' => $event->capacity,
                    'remaining' => $remaining,
                    'is_full' => $isFull,
                    'waitlist_available' => $isFull && $event->waitlist_enabled && $waitlistFeature,
                ];
            })->values()->all(),
        ];
    }

    /**
     * The customer-facing form of a quote_request service (SLO-102): the labels
     * the tenant asks for, and whether the feature is on at all — a tenant whose
     * feature_quote_request was switched off keeps the service but stops taking
     * requests, so the page says so instead of offering a form that would 403.
     *
     * @return array{quote_fields: list<string>, quote_enabled: bool}
     */
    private function quoteView(Service $service): array
    {
        return [
            'quote_fields' => $service->quoteFields(),
            'quote_enabled' => app(FeatureResolver::class)->enabled(
                app(TenantManager::class)->current(),
                Feature::QuoteRequest,
            ),
        ];
    }
}
