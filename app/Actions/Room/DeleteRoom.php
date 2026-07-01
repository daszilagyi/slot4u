<?php

namespace App\Actions\Room;

use App\Models\Room;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a room (SLO-16). Guarded: a room with upcoming bookings cannot be
 * hard-deleted — it must be inactivated instead. The bookings table arrives
 * with M3, so today the guard is a forward-compatible no-op (see
 * Room::hasFutureBookings()).
 */
class DeleteRoom
{
    public function __invoke(Room $room): void
    {
        if ($room->hasFutureBookings()) {
            throw ValidationException::withMessages([
                'delete' => __('app.admin.locations.error.room_has_bookings'),
            ]);
        }

        $room->delete();
    }
}
