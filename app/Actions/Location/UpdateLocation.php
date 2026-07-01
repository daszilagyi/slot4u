<?php

namespace App\Actions\Location;

use App\Models\Location;

/**
 * Updates a location's editable fields (SLO-16).
 */
class UpdateLocation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Location $location, array $data): void
    {
        $location->update($data);
    }
}
