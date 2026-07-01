<?php

namespace App\Actions\Room;

use App\Enums\PlanLimitKey;
use App\Models\Location;
use App\Models\Room;
use App\Services\Plan\PlanLimitService;
use Illuminate\Validation\ValidationException;

/**
 * Creates a room inside a location, enforcing the base plan's tenant-wide
 * max_rooms limit (SLO-16). location_id comes from the parent relation and
 * tenant_id from BelongsToTenant.
 */
class CreateRoom
{
    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(Location $location, array $data): Room
    {
        if (! $this->limits->withinLimit(PlanLimitKey::MaxRooms, Room::count())) {
            throw ValidationException::withMessages([
                'name' => __('app.admin.locations.error.limit_rooms'),
            ]);
        }

        return $location->rooms()->create($data);
    }
}
