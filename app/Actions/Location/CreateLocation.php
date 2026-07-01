<?php

namespace App\Actions\Location;

use App\Enums\PlanLimitKey;
use App\Models\Location;
use App\Services\Plan\PlanLimitService;
use Illuminate\Validation\ValidationException;

/**
 * Creates a tenant location, enforcing the base plan's max_locations limit
 * (SLO-16). tenant_id is stamped by BelongsToTenant, not the request.
 */
class CreateLocation
{
    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(array $data): Location
    {
        if (! $this->limits->withinLimit(PlanLimitKey::MaxLocations, Location::count())) {
            throw ValidationException::withMessages([
                'name' => __('app.admin.locations.error.limit_locations'),
            ]);
        }

        return Location::create($data);
    }
}
