<?php

namespace App\Http\Requests\Concerns;

use App\Support\BookingVisibility;
use Illuminate\Contracts\Validation\Validator;

/**
 * The employee "saját naptárba" scope on the write side of a booking (docs/03
 * matrix, SLO-178).
 *
 * The staff picker in the UI is already narrowed to the actor's own calendars,
 * but a picker is decoration — the id arrives in the request body, and until
 * this landed nothing on the server said it had to be theirs. Three requests
 * carry that id (create, reschedule, propose-alternative) and each is a way in,
 * so the rule lives in one place rather than three.
 *
 * The read side is the same {@see BookingVisibility}: an existing booking
 * outside the scope 404s before any of this runs.
 */
trait ScopesBookingCalendar
{
    /**
     * Reject a `staff_id` outside the actor's own calendars.
     *
     * `$required` separates the two shapes this takes. On CREATE the id must be
     * present and owned: a restricted actor creating a booking with no staff (a
     * room rental, a no_time_slot service) would create one that immediately
     * falls outside their own list — invisible to them the moment it is saved,
     * so not cancellable or even findable. On a MOVE the id is optional, because
     * absent means "keep the resource it already has", and the booking being
     * moved was ownership-checked before the request got here.
     */
    protected function validateOwnCalendar(Validator $validator, bool $required): void
    {
        $actor = $this->user();

        if ($actor === null || BookingVisibility::unrestricted($actor)) {
            return;
        }

        $staffId = $this->input('staff_id');

        if ($staffId === null) {
            if ($required) {
                $validator->errors()->add('staff_id', __('app.admin.bookings.error.own_calendar_required'));
            }

            return;
        }

        if (! BookingVisibility::ownsStaffId($actor, (int) $staffId)) {
            $validator->errors()->add('staff_id', __('app.admin.bookings.error.foreign_calendar'));
        }
    }
}
