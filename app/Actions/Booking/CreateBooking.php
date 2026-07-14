<?php

namespace App\Actions\Booking;

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\WaitlistService;
use App\Settings\TenantSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a booking, race-condition-safe (docs/02 ütközésvédelem, docs/04, SLO-24):
 * two concurrent requests for the same slot/seat — exactly one wins.
 *
 * - Time-slot modes (duration_based / resource_rental): the resource row is locked
 *   FOR UPDATE inside the transaction, then the overlap is re-checked under the
 *   lock before insert, so concurrent inserts for the same staff/room serialise.
 * - event_based: an atomic conditional UPDATE claims capacity
 *   (`booked_count + party_size <= capacity`); zero rows affected = full.
 * - no_time_slot: created straight to its initial status.
 *
 * The initial status follows the service config (approval → requested, online
 * payment → pending_payment, else confirmed); an admin booking skips those gates.
 * The caller sets `source`: the admin controller forces `admin`; the M4 public
 * flow (SLO-32) MUST set `online` so a customer can't skip the approval/payment
 * gate. `price_minor` is the per-booking price snapshot (not per party member).
 *
 * The "exactly one wins" guarantee rests on the DB (FOR UPDATE serialisation +
 * the atomic conditional UPDATE); the SQLite :memory: test suite can't simulate
 * concurrent connections, so the tests assert the guard sequentially — true
 * concurrency is a MySQL/staging integration concern.
 */
class CreateBooking
{
    public function __construct(
        private readonly WaitlistService $waitlist,
        private readonly ChangeBookingStatus $changeStatus,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Booking|null  $rescheduledFrom  the booking this one replaces, when created by
     *                                         a reschedule (docs/04 §2). Recorded on the new
     *                                         row so the lifecycle listeners send a single
     *                                         "moved" mail rather than a confirmation, and so
     *                                         the replacement chain stays auditable (SLO-109).
     *                                         Passed as a model (never a request key) — the
     *                                         column is guarded against mass assignment.
     *
     * @throws SlotUnavailableException
     */
    public function __invoke(Service $service, array $data, ?User $actor = null, ?Booking $rescheduledFrom = null): Booking
    {
        // The replacement chain must never cross a tenant boundary: both entry points
        // pass a route-bound (tenant-scoped) booking today, but the Action layer is
        // entry-point-independent (docs/01 §4 — the Phase-2 API and queue jobs call it
        // too), and a foreign predecessor would leak the other tenant's old time into
        // the "moved" mail.
        if ($rescheduledFrom !== null && (int) $rescheduledFrom->tenant_id !== (int) $service->tenant_id) {
            throw new RuntimeException('A booking can only replace another booking of the same tenant (SLO-109).');
        }

        $source = BookingSource::tryFrom((string) ($data['source'] ?? '')) ?? BookingSource::Admin;
        $status = $this->initialStatus($service, $source);

        // A requested (approval-pending) booking soft-holds its slot until the
        // tenant's approval-hold window elapses (docs/04 §5, SLO-26).
        $holdExpiresAt = $status === BookingStatus::Requested
            ? Carbon::now()->addHours($this->approvalHoldHours($service))
            : null;

        $attributes = [
            'customer_id' => $data['customer_id'] ?? null,
            'service_id' => $service->id,
            'booking_mode' => $service->booking_mode,
            'staff_id' => $data['staff_id'] ?? null,
            'room_id' => $data['room_id'] ?? null,
            'event_id' => $data['event_id'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'party_size' => (int) ($data['party_size'] ?? 1),
            'price_minor' => $service->price_minor,
            'currency' => $service->currency,
            'notes' => $data['notes'] ?? null,
            'source' => $source,
        ];

        // Only a time-slot booking can be rescheduled (docs/04 §2, guarded in
        // RescheduleBooking) — the other modes never receive a predecessor.
        return match ($service->booking_mode) {
            BookingMode::EventBased => $this->createEventBooking($attributes, $status, (int) $service->tenant_id, $holdExpiresAt),
            BookingMode::DurationBased, BookingMode::ResourceRental => $this->createTimeSlotBooking($attributes, $status, $service, $holdExpiresAt, $rescheduledFrom),
            BookingMode::NoTimeSlot => $this->createNoTimeSlotBooking($attributes, $status, $service, $holdExpiresAt),
            // A quote_request booking is generated only when an accepted quote is
            // turned into an engagement (docs/04 §6, SLO-27) — never time-slotted,
            // and it carries the accepted quote price passed in $data.
            BookingMode::QuoteRequest => $this->createQuoteBooking($attributes, $status, $data),
        };
    }

    private function approvalHoldHours(Service $service): int
    {
        $tenant = Tenant::withoutGlobalScopes()->find($service->tenant_id);

        return TenantSettings::fromArray($tenant?->settings)->approvalHoldHours;
    }

    private function initialStatus(Service $service, BookingSource $source): BookingStatus
    {
        // An admin acts on the customer's behalf and skips the approval/payment
        // gates (docs/04: admin-oldali létrehozás, szabályok lazítva).
        if ($source === BookingSource::Admin) {
            return BookingStatus::Confirmed;
        }

        if ($service->requires_approval) {
            return BookingStatus::Requested;
        }

        if ($service->online_payment_required) {
            return BookingStatus::PendingPayment;
        }

        return BookingStatus::Confirmed;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTimeSlotBooking(array $attributes, BookingStatus $status, Service $service, ?Carbon $holdExpiresAt, ?Booking $rescheduledFrom = null): Booking
    {
        return DB::transaction(function () use ($attributes, $status, $service, $holdExpiresAt, $rescheduledFrom): Booking {
            // Lock the resource row(s) so concurrent bookings for the same staff/
            // room serialise even when no conflicting booking exists yet.
            if ($attributes['staff_id'] !== null) {
                Staff::query()->whereKey($attributes['staff_id'])->lockForUpdate()->first();
            }
            if ($attributes['room_id'] !== null) {
                Room::query()->whereKey($attributes['room_id'])->lockForUpdate()->first();
            }

            if ($this->hasResourceConflict($attributes, $service)) {
                throw SlotUnavailableException::slotTaken();
            }

            return $this->persist($attributes, $status, $holdExpiresAt, $rescheduledFrom);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function hasResourceConflict(array $attributes, Service $service): bool
    {
        if ($attributes['starts_at'] === null || $attributes['ends_at'] === null) {
            return false;
        }
        if ($attributes['staff_id'] === null && $attributes['room_id'] === null) {
            return false;
        }

        $before = $service->booking_mode->supportsBuffers() ? $service->buffer_before_minutes : 0;
        $after = $service->booking_mode->supportsBuffers() ? $service->buffer_after_minutes : 0;

        $start = Carbon::parse($attributes['starts_at'])->subMinutes($before);
        $end = Carbon::parse($attributes['ends_at'])->addMinutes($after);

        return Booking::query()
            // Explicit tenant anchor (defense-in-depth: the ambient TenantScope is a
            // no-op for non-request callers — queue jobs, the Phase-2 API).
            ->where('tenant_id', $service->tenant_id)
            ->whereIn('status', BookingStatus::occupyingValues())
            // Only match the resources actually being booked — a null staff/room
            // must NOT match every other resourceless booking.
            ->where(function ($query) use ($attributes): void {
                $query->when($attributes['staff_id'] !== null, fn ($q) => $q->orWhere('staff_id', $attributes['staff_id']));
                $query->when($attributes['room_id'] !== null, fn ($q) => $q->orWhere('room_id', $attributes['room_id']));
            })
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createEventBooking(array $attributes, BookingStatus $status, int $tenantId, ?Carbon $holdExpiresAt): Booking
    {
        return DB::transaction(function () use ($attributes, $status, $tenantId, $holdExpiresAt): Booking {
            $eventId = $attributes['event_id'];
            $partySize = $attributes['party_size'];

            // Atomic capacity claim: only succeeds while there is room (docs/02).
            // Explicit tenant anchor (defense-in-depth; see hasResourceConflict).
            $claimed = Event::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($eventId)
                ->whereRaw('booked_count + ? <= capacity', [$partySize])
                ->update(['booked_count' => DB::raw('booked_count + '.(int) $partySize)]);

            if ($claimed === 0) {
                throw SlotUnavailableException::eventFull();
            }

            /** @var Event $event */
            $event = Event::query()->findOrFail($eventId);
            $attributes['starts_at'] = $event->starts_at;
            $attributes['ends_at'] = $event->ends_at;

            $booking = $this->persist($attributes, $status, $holdExpiresAt);

            // If the customer was on this event's waitlist, close their entry and
            // hand any remaining freed capacity to the next waiter (docs/04 §3,
            // SLO-25).
            $customerId = $attributes['customer_id'];
            if ($customerId !== null && $this->waitlist->markConverted($eventId, $tenantId, (int) $customerId) > 0) {
                $this->waitlist->offerNext($eventId, $tenantId);
            }

            return $booking;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createNoTimeSlotBooking(array $attributes, BookingStatus $status, Service $service, ?Carbon $holdExpiresAt): Booking
    {
        $booking = $this->persist($attributes, $status, $holdExpiresAt);

        // Digital fulfilment delivers instantly (docs/04 §1: "azonnali link"): a
        // confirmed digital booking is completed on the spot. Manual/downloadable
        // stay confirmed until an admin closes them (CompleteBooking, SLO-27).
        if ($status === BookingStatus::Confirmed && $service->fulfillmentType() === 'digital') {
            ($this->changeStatus)($booking, BookingStatus::Completed);
        }

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     */
    private function createQuoteBooking(array $attributes, BookingStatus $status, array $data): Booking
    {
        // A quote_request booking is ONLY ever created by accepting a quote
        // (AcceptQuoteRequest, docs/04 §6) — never through the generic booking
        // endpoint, which would bypass the whole new→quoted→accepted lifecycle and
        // book at the service list price. The accept path sets this internal flag;
        // any other caller is refused. (BookingRequest also rejects the mode at the
        // HTTP layer — defence in depth.)
        if (($data['from_quote_acceptance'] ?? false) !== true) {
            throw new RuntimeException('A quote_request booking can only be created by accepting a quote (SLO-27).');
        }

        // The accepted quote price is authoritative (docs/04 §6), overriding the
        // service list price snapshot.
        if (isset($data['price_minor'])) {
            $attributes['price_minor'] = (int) $data['price_minor'];
        }
        if (! empty($data['currency'])) {
            $attributes['currency'] = (string) $data['currency'];
        }

        return $this->persist($attributes, $status, null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(array $attributes, BookingStatus $status, ?Carbon $holdExpiresAt = null, ?Booking $rescheduledFrom = null): Booking
    {
        $booking = new Booking;
        $booking->fill($attributes);
        // status, hold_expires_at and rescheduled_from_id are guarded (not fillable)
        // — set from the action.
        $booking->status = $status;
        $booking->hold_expires_at = $holdExpiresAt;
        // Must be set BEFORE the insert: the model's created hook dispatches
        // BookingCreated, and the notification listener reads the predecessor off
        // the booking to pick the "moved" mail over the confirmation (SLO-109).
        $booking->rescheduled_from_id = $rescheduledFrom?->getKey();
        $booking->save();

        return $booking;
    }
}
