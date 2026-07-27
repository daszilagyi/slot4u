<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\ChangeBookingStatus;
use App\Actions\Booking\CompleteBooking;
use App\Actions\Booking\CreateBooking;
use App\Actions\Booking\RescheduleBooking;
use App\Actions\Payment\RefundBookingPayments;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BookingRequest;
use App\Http\Requests\Admin\CancelBookingRequest;
use App\Http\Requests\Admin\RefundBookingRequest;
use App\Http\Requests\Admin\RescheduleBookingRequest;
use App\Jobs\IssueInvoice;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Support\BookingVisibility;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin booking management (SLO-24 create, SLO-85 list + quick actions). Lives
 * behind auth + ensure.user.tenant + the booking.* permissions (routes/tenant.php).
 * The list is tenant-scoped (BelongsToTenant) and further narrowed to what the
 * actor may see ({@see BookingVisibility}: an employee sees only their own staff's
 * bookings); a booking outside that scope surfaces as a 404, like a cross-tenant
 * id. Every write goes through the action layer (race-safe create, state-machine
 * status changes); an illegal transition or taken slot surfaces as a 422.
 */
class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Booking::class);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', BookingStatus::values())],
            'staff_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $actor = $request->user();

        [$fromUtc, $toUtc] = $this->dateRange($filters['from'] ?? null, $filters['to'] ?? null);

        $query = Booking::query()
            ->with(['customer:id,name,email', 'service:id,name', 'staff:id,name'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['staff_id'] ?? null, fn (Builder $q, int $staffId) => $q->where('staff_id', $staffId))
            ->when($filters['service_id'] ?? null, fn (Builder $q, int $serviceId) => $q->where('service_id', $serviceId))
            ->when($fromUtc, fn (Builder $q) => $q->where('starts_at', '>=', $fromUtc))
            ->when($toUtc, fn (Builder $q) => $q->where('starts_at', '<=', $toUtc))
            ->orderByDesc('starts_at')
            ->orderByDesc('id');

        // Employees see only their own bookings (docs/03 "saját foglalásai").
        BookingVisibility::apply($query, $actor);

        $bookings = $query->paginate(20)->withQueryString()
            ->through(fn (Booking $booking) => $this->summary($booking));

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $bookings,
            'filters' => [
                'status' => $filters['status'] ?? null,
                'staff_id' => $filters['staff_id'] ?? null,
                'service_id' => $filters['service_id'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'statuses' => BookingStatus::values(),
                'staff' => $this->staffOptions($actor),
                'services' => Service::query()->orderBy('name')->get(['id', 'name']),
            ],
            'can' => [
                'edit' => (bool) $actor->can(Permission::BookingEdit->value),
                'cancel' => (bool) $actor->can(Permission::BookingCancel->value),
            ],
        ]);
    }

    // $tenant absorbs the subdomain route parameter (before the bound {booking}).
    public function show(Request $request, string $tenant, Booking $booking): Response
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('view', $booking);

        return Inertia::render('Admin/Bookings/Show', [
            'booking' => $this->detail($booking),
            // What the customer paid online and what came back (SLO-131). Only
            // meaningful for a tenant with the integration, but harmless (and empty)
            // without it.
            'payments' => $this->payments($booking),
            'refundable_minor' => app(RefundBookingPayments::class)->refundableMinor($booking),
            // The customer invoice for this booking, when the tenant invoices
            // online (SLO-133); null otherwise.
            'invoice' => $this->invoice($booking),
            'can' => [
                'edit' => (bool) $actor->can(Permission::BookingEdit->value),
                'cancel' => (bool) $actor->can(Permission::BookingCancel->value),
            ],
        ]);
    }

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
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('update', $booking);

        $completeBooking($booking, $actor);

        return back();
    }

    /**
     * Cancel a booking from the admin list (SLO-85). Admin-initiated: no
     * cancellation-deadline check ({@see CancelBooking} with online:false). Gated
     * by booking.cancel; an illegal transition surfaces as a 422.
     */
    public function cancel(CancelBookingRequest $request, string $tenant, Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('cancel', $booking);

        $cancelBooking($booking, $actor, $request->validated('reason'));

        return back();
    }

    /**
     * Refund a booking by hand (SLO-131) — the override on top of the tenant's
     * automatic refund policy: a goodwill refund for a booking cancelled under a
     * `none` policy, a top-up after a partial one, or the money back for a booking
     * that was never cancelled at all. Gated by booking.cancel; the amount is
     * capped at what is still refundable, and 422s when nothing is.
     */
    public function refund(RefundBookingRequest $request, string $tenant, Booking $booking, RefundBookingPayments $refundPayments): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('cancel', $booking);

        $refunds = $refundPayments(
            $booking,
            (int) $request->validated('amount_minor'),
            $request->validated('reason'),
        );

        // Nothing settled to refund (or already fully refunded) — tell the admin
        // rather than silently doing nothing.
        if ($refunds === []) {
            throw ValidationException::withMessages([
                'amount_minor' => __('app.admin.bookings.error.nothing_to_refund'),
            ]);
        }

        return back();
    }

    /**
     * Mark a confirmed booking as no-show (docs/04 §5, SLO-85). Goes through the
     * shared state machine; confirmed → no_show only (an illegal transition → 422).
     */
    public function noShow(Request $request, string $tenant, Booking $booking, ChangeBookingStatus $changeStatus): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('update', $booking);

        $changeStatus($booking, BookingStatus::NoShow, $actor);

        return back();
    }

    /**
     * Reschedule a time-slot booking (docs/04 §2, SLO-85): cancel the old + create
     * the new in one transaction, keeping the original service. An unavailable new
     * slot rolls the whole thing back (the original survives) and surfaces as a 422.
     */
    public function reschedule(RescheduleBookingRequest $request, string $tenant, Booking $booking, RescheduleBooking $reschedule): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('update', $booking);

        $data = $request->validated();
        $service = Service::query()->findOrFail($booking->service_id);

        $reschedule($booking, $service, [
            'service_id' => $service->id,
            'customer_id' => $booking->customer_id,
            'staff_id' => $data['staff_id'] ?? $booking->staff_id,
            'room_id' => $data['room_id'] ?? $booking->room_id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'party_size' => $booking->party_size,
            'source' => BookingSource::Admin->value,
        ], $actor);

        return back();
    }

    /**
     * Interpret the tenant-local from/to date filters as full-day bounds in UTC
     * (docs/01 §7): from → start of that day, to → end of that day.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function dateRange(?string $from, ?string $to): array
    {
        $tenant = app(TenantManager::class)->current();
        $tz = $tenant !== null ? $tenant->timezone : (string) config('app.timezone');

        $fromUtc = $from !== null ? Carbon::parse($from, $tz)->startOfDay()->utc()->toDateTimeString() : null;
        $toUtc = $to !== null ? Carbon::parse($to, $tz)->endOfDay()->utc()->toDateTimeString() : null;

        return [$fromUtc, $toUtc];
    }

    /**
     * Staff options for the filter: every staff for the unrestricted roles, only
     * the actor's own staff for an employee (mirrors the list scope).
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function staffOptions(User $actor): Collection
    {
        $query = Staff::query()->orderBy('name');

        if (! BookingVisibility::unrestricted($actor)) {
            $query->whereIn('id', BookingVisibility::actorStaffIds($actor));
        }

        return $query->get(['id', 'name'])->map(fn (Staff $staff) => [
            'id' => $staff->id,
            'name' => $staff->name,
        ]);
    }

    /**
     * Row DTO for the list.
     *
     * @return array<string, mixed>
     */
    private function summary(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'code' => $booking->code,
            // A guest booking (SLO-128) has no account behind it — show the name it
            // was made with, flagged so the row reads honestly.
            'customer' => $booking->contactName(),
            'is_guest' => $booking->isGuest(),
            'service' => $booking->service?->name,
            'staff' => $booking->staff?->name,
            'status' => $booking->status->value,
            'booking_mode' => $booking->booking_mode->value,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'price_minor' => (int) $booking->price_minor,
            'currency' => $booking->currency,
        ];
    }

    /**
     * Re-issue an invoice the provider refused (SLO-133). The queued issuer is
     * idempotent, so this is safe to press twice; an already issued invoice is a
     * 422 rather than a silent second document.
     */
    public function retryInvoice(Request $request, string $tenant, Booking $booking, Invoice $invoice): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('update', $booking);
        // Route-bound and tenant-scoped, but the pair must match: an invoice of
        // another booking is not this page's to retry.
        abort_unless((int) $invoice->booking_id === (int) $booking->getKey(), 404);

        if (! $invoice->status->isRetryable()) {
            throw ValidationException::withMessages([
                'invoice' => __('app.admin.bookings.error.invoice_not_retryable'),
            ]);
        }

        IssueInvoice::dispatch($invoice->getKey());

        return back();
    }

    /**
     * Stream an invoice PDF to the tenant's staff (SLO-133). Behind auth + the
     * booking visibility scope; never a public URL.
     */
    public function invoicePdf(Request $request, string $tenant, Booking $booking, Invoice $invoice): StreamedResponse
    {
        $actor = $request->user();
        abort_unless(BookingVisibility::owns($actor, $booking), 404);
        Gate::authorize('view', $booking);
        abort_unless((int) $invoice->booking_id === (int) $booking->getKey(), 404);

        $path = $invoice->downloadablePath();
        abort_if($path === null, 404);

        $disk = Storage::disk((string) config('invoicing.disk'));
        abort_unless($disk->exists($path), 404);

        $number = $invoice->storno_number ?? $invoice->number ?? (string) $invoice->id;

        return $disk->download($path, 'szamla-'.str_replace('/', '-', $number).'.pdf');
    }

    /**
     * The booking's invoice, if the tenant invoices online at all (SLO-133).
     *
     * @return array<string, mixed>|null
     */
    private function invoice(Booking $booking): ?array
    {
        $invoice = Invoice::query()->where('booking_id', $booking->getKey())->latest('id')->first();

        if (! $invoice instanceof Invoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->storno_number ?? $invoice->number,
            'status' => $invoice->status->value,
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'error' => $invoice->error,
            'can_retry' => $invoice->status->isRetryable(),
            'has_pdf' => $invoice->downloadablePath() !== null,
        ];
    }

    /**
     * The booking's online payment attempts with their refunds (SLO-131), newest
     * first. Eager-loaded in one extra query each — no N+1 over the attempts.
     *
     * @return list<array<string, mixed>>
     */
    private function payments(Booking $booking): array
    {
        return Payment::query()
            ->where('booking_id', $booking->getKey())
            ->with(['refunds' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'provider' => $payment->provider->value,
                'status' => $payment->status->value,
                'amount_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'refunds' => $payment->refunds->map(fn (Refund $refund): array => [
                    'id' => $refund->id,
                    'status' => $refund->status->value,
                    'amount_minor' => $refund->amount_minor,
                    'currency' => $refund->currency,
                    'reason' => $refund->reason,
                    'refunded_at' => $refund->refunded_at?->toIso8601String(),
                ])->all(),
            ])
            ->all();
    }

    /**
     * Detail DTO for the booking card: the booking + its status-change history
     * (N+1-safe eager load of the actor names).
     *
     * @return array<string, mixed>
     */
    private function detail(Booking $booking): array
    {
        // phone is selected too: the card shows the contact's phone, which comes
        // from the account for a customer and from the row for a guest (SLO-128).
        $booking->load(['customer:id,name,email,phone', 'service:id,name', 'staff:id,name', 'room:id,name']);

        $history = $booking->statusHistory()
            ->with('actor:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status->value,
                'actor' => $entry->actor?->name,
                'created_at' => $entry->created_at->toIso8601String(),
            ]);

        return [
            'id' => $booking->id,
            'code' => $booking->code,
            'status' => $booking->status->value,
            'booking_mode' => $booking->booking_mode->value,
            'customer' => $booking->contactName(),
            'customer_email' => $booking->contactEmail(),
            'customer_phone' => $booking->contactPhone(),
            'is_guest' => $booking->isGuest(),
            'service' => $booking->service?->name,
            'staff' => $booking->staff?->name,
            'room' => $booking->room?->name,
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'party_size' => (int) $booking->party_size,
            'price_minor' => (int) $booking->price_minor,
            'currency' => $booking->currency,
            'notes' => $booking->notes,
            'cancel_reason' => $booking->cancel_reason,
            'history' => $history,
        ];
    }
}
