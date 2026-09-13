<?php

namespace App\Actions\Waitlist;

use App\Actions\Customer\PublicContact;
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
 *
 * The waiter is a customer id (admin join) or a resolved {@see PublicContact}
 * from the public form, which may be an account-less guest (SLO-228). A guest is
 * "already listed" when an active entry on the event carries the same email —
 * the only identity a guest has.
 */
class JoinWaitlist
{
    /**
     * @throws ValidationException
     */
    public function __invoke(Event $event, int|PublicContact $waiter, int $partySize = 1): WaitlistEntry
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

        $contact = is_int($waiter)
            ? ['customer_id' => $waiter, 'guest_name' => null, 'guest_email' => null, 'guest_phone' => null]
            : $waiter->recordAttributes();

        return DB::transaction(function () use ($event, $tenantId, $contact, $partySize): WaitlistEntry {
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
                ->when(
                    $contact['customer_id'] !== null,
                    fn ($query) => $query->where('customer_id', $contact['customer_id']),
                    fn ($query) => $query->whereNull('customer_id')->where('guest_email', $contact['guest_email']),
                )
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
                'party_size' => $partySize,
            ] + $contact);
            $entry->position = $position;
            $entry->status = WaitlistStatus::Waiting;
            $entry->save();

            return $entry;
        });
    }
}
