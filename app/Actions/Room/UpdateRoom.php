<?php

namespace App\Actions\Room;

use App\Models\Room;

/**
 * Updates a room's editable fields (SLO-16).
 */
class UpdateRoom
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Room $room, array $data): void
    {
        $room->update($data);
    }
}
