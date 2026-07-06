<?php

namespace App\Services\Booking;

use App\Actions\Booking\CreateBooking;
use App\Enums\EventStatus;
use App\Enums\WaitlistStatus;
use App\Events\WaitlistOffered;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Settings\TenantSettings;
use Illuminate\Support\Carbon;

/**
 * The event waitlist engine (docs/04 §3, SLO-25). Turns a freed event seat into a
 * FIFO offer, converts a waiter who books, and expires stale offers. Every query
 * is explicitly tenant-anchored so it is correct from a request, a queue job and
 * the (tenant-less) scheduled expiry command alike.
 *
 * Offering is strictly serial per event — at most one `offered` entry at a time —
 * so the queue is drained in fair FIFO order (parallel offers are a Phase-2
 * refinement). The freed seat is a soft hold: capacity is the source of truth
 * (booked_count), the offer is a head start for the next waiter, and the atomic
 * claim in {@see CreateBooking} still governs who gets it.
 */
class WaitlistService
{
    /**
     * Promote the earliest waiting entry to an offer, if the event has a free seat
     * and no offer is already outstanding. No-op otherwise. Returns the offered
     * entry (or null when nothing was offered).
     */
    public function offerNext(int $eventId, int $tenantId, ?Carbon $now = null): ?WaitlistEntry
    {
        $now ??= Carbon::now();

        // Serial FIFO: never run two offers for the same event at once. The expiry
        // command is what clears a lapsed offer and chains to the next waiter.
        $hasActiveOffer = WaitlistEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('status', WaitlistStatus::Offered->value)
            ->exists();

        if ($hasActiveOffer) {
            return null;
        }

        $event = Event::query()->where('tenant_id', $tenantId)->find($eventId);
        // A canceled event has nothing to offer — releasing a seat on it (e.g. a
        // registrant canceling after the event was called off) must not promote a
        // waiter onto a dead occurrence.
        if ($event === null || $event->status !== EventStatus::Scheduled) {
            return null;
        }

        $next = WaitlistEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('status', WaitlistStatus::Waiting->value)
            ->orderBy('position')
            ->first();

        if ($next === null) {
            return null;
        }

        // The freed capacity must actually fit the earliest waiter's party, else
        // the offer would be unfulfillable (the atomic claim in CreateBooking would
        // reject it). Strict FIFO: we do NOT leapfrog to a smaller party that would
        // fit — the head of the queue waits until enough seats accumulate (parallel
        // best-fit offering is a Phase-2 refinement).
        if ($event->booked_count + $next->party_size > $event->capacity) {
            return null;
        }

        $next->status = WaitlistStatus::Offered;
        $next->offered_until = $now->copy()->addHours($this->offerHours($event));
        $next->save();

        WaitlistOffered::dispatch($next);

        return $next;
    }

    /**
     * Close the loop when a waitlisted customer books the event: flip their active
     * entry to `converted`. Returns the number of entries converted (0 when the
     * booking customer was not on the list).
     */
    public function markConverted(int $eventId, int $tenantId, int $customerId): int
    {
        return WaitlistEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('event_id', $eventId)
            ->where('customer_id', $customerId)
            ->whereIn('status', WaitlistStatus::activeValues())
            ->update([
                'status' => WaitlistStatus::Converted->value,
                'offered_until' => null,
            ]);
    }

    /**
     * Expire every offer past its window and offer the freed place to the next
     * waiter (scheduled command). Tenant-less: scans all tenants. Returns the
     * number of offers expired.
     */
    public function expireDueOffers(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $due = WaitlistEntry::query()
            ->where('status', WaitlistStatus::Offered->value)
            ->whereNotNull('offered_until')
            ->where('offered_until', '<=', $now)
            ->get();

        foreach ($due as $entry) {
            $entry->status = WaitlistStatus::Expired;
            $entry->offered_until = null;
            $entry->save();
        }

        // Chain to the next waiter per affected event (the active offer has cleared).
        $pairs = $due
            ->map(fn (WaitlistEntry $e) => $e->tenant_id.':'.$e->event_id)
            ->unique();

        foreach ($pairs as $pair) {
            [$tenantId, $eventId] = explode(':', (string) $pair);
            $this->offerNext((int) $eventId, (int) $tenantId, $now);
        }

        return $due->count();
    }

    /**
     * Close out the active waitlist of canceled events (docs/04 §3): a `waiting`/
     * `offered` place on an event that no longer happens is moot, so it is expired.
     * Registrant + waitlist notifications ride the status change in M5 (SLO-35).
     * Returns the number of entries expired.
     *
     * @param  list<int>  $eventIds
     */
    public function expireForEvents(array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }

        return WaitlistEntry::query()
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', WaitlistStatus::activeValues())
            ->update([
                'status' => WaitlistStatus::Expired->value,
                'offered_until' => null,
            ]);
    }

    /**
     * The tenant's configured offer window in hours (defaults to 24, SLO-21/25).
     */
    private function offerHours(Event $event): int
    {
        $tenant = Tenant::withoutGlobalScopes()->find($event->tenant_id);

        return TenantSettings::fromArray($tenant?->settings)->waitlistOfferHours;
    }
}
