<?php

namespace App\Actions\Waitlist;

use App\Enums\WaitlistStatus;
use App\Http\Requests\Admin\WaitlistRequest;
use App\Models\Event;
use App\Models\WaitlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a customer to the FIFO waitlist of a full event (docs/04 §3, SLO-25). The
 * event row is locked so the full-check and position assignment serialise: two
 * concurrent joins get distinct, gap-free positions. Rejects a join when the event
 * has no waitlist, still has a free seat (book directly), or already lists the
 * customer. The tenant feature gate lives in {@see WaitlistRequest}.
 */
class JoinWaitlist
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Event $event, int $customerId, int $partySize = 1): WaitlistEntry
    {
        if (! $event->waitlist_enabled) {
            throw ValidationException::withMessages([
                'waitlist' => __('app.admin.waitlist.error.not_enabled'),
            ]);
        }

        // Explicit tenant anchor (defense-in-depth: the ambient TenantScope is a
        // no-op for non-request callers — queue jobs, the Phase-2 API — mirroring
        // CreateBooking's event path).
        $tenantId = (int) $event->tenant_id;

        return DB::transaction(function () use ($event, $tenantId, $customerId, $partySize): WaitlistEntry {
            // Lock the event row so the full-check + position assignment can't race.
            /** @var Event $locked */
            $locked = Event::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($event->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->booked_count < $locked->capacity) {
                throw ValidationException::withMessages([
                    'waitlist' => __('app.admin.waitlist.error.not_full'),
                ]);
            }

            $alreadyListed = WaitlistEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)
                ->where('customer_id', $customerId)
                ->whereIn('status', WaitlistStatus::activeValues())
                ->exists();

            if ($alreadyListed) {
                throw ValidationException::withMessages([
                    'waitlist' => __('app.admin.waitlist.error.already_listed'),
                ]);
            }

            $position = (int) WaitlistEntry::query()
                ->where('tenant_id', $tenantId)
                ->where('event_id', $event->id)
                ->max('position') + 1;

            $entry = new WaitlistEntry;
            // tenant_id is stamped by BelongsToTenant; status/position are guarded.
            $entry->fill([
                'event_id' => $event->id,
                'service_id' => $event->service_id,
                'customer_id' => $customerId,
                'party_size' => $partySize,
            ]);
            $entry->position = $position;
            $entry->status = WaitlistStatus::Waiting;
            $entry->save();

            return $entry;
        });
    }
}
