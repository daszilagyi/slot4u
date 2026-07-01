<?php

namespace App\Actions\Location;

use App\Models\Location;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a location (SLO-16). Guarded: a location that still has rooms cannot
 * be deleted (its rooms would cascade away) — remove or move the rooms first.
 */
class DeleteLocation
{
    public function __invoke(Location $location): void
    {
        if ($location->rooms()->exists()) {
            throw ValidationException::withMessages([
                'delete' => __('app.admin.locations.error.location_has_rooms'),
            ]);
        }

        $location->delete();
    }
}
