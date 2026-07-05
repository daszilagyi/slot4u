<?php

namespace App\Services\Booking;

use Illuminate\Support\Carbon;

/**
 * A free bookable slot produced by {@see AvailabilityService} (SLO-22). Times are
 * UTC instants (docs/01 §7); the resource ids say which staff/room the slot is
 * free for so the booking flow (SLO-24) can hold exactly that resource.
 */
final class Slot
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly ?int $staffId = null,
        public readonly ?int $roomId = null,
    ) {}

    /**
     * @return array{start: string, end: string, staff_id: int|null, room_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
            'staff_id' => $this->staffId,
            'room_id' => $this->roomId,
        ];
    }
}
