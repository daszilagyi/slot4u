<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Location\CreateLocation;
use App\Actions\Location\DeleteLocation;
use App\Actions\Location\UpdateLocation;
use App\Enums\PlanLimitKey;
use App\Enums\RoomType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\Location;
use App\Models\Room;
use App\Services\Plan\PlanLimitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant location + room management (SLO-16). Lives behind
 * auth + ensure.user.tenant + can:location.manage (routes/tenant.php); business
 * rules (plan limits, delete protection) live in the Action classes.
 */
class LocationController extends Controller
{
    public function index(PlanLimitService $limits): Response
    {
        Gate::authorize('viewAny', Location::class);

        // Eager-load rooms to keep the nested render N+1-free.
        $locations = Location::query()
            ->with(['rooms' => fn ($query) => $query->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Locations/Index', [
            'locations' => $locations->map(fn (Location $location) => $this->locationData($location))->values(),
            'limits' => [
                'locations' => [
                    'used' => $locations->count(),
                    'max' => $limits->limitFor(PlanLimitKey::MaxLocations),
                ],
                'rooms' => [
                    'used' => $locations->sum(fn (Location $location) => $location->rooms->count()),
                    'max' => $limits->limitFor(PlanLimitKey::MaxRooms),
                ],
            ],
            'roomTypes' => RoomType::values(),
        ]);
    }

    public function store(LocationRequest $request, CreateLocation $createLocation): RedirectResponse
    {
        Gate::authorize('create', Location::class);

        $createLocation($request->validated());

        return back();
    }

    // $tenant is the subdomain route parameter (docs/01); it is consumed here so
    // the {location} path parameter binds by name to the Location model.
    public function update(LocationRequest $request, string $tenant, Location $location, UpdateLocation $updateLocation): RedirectResponse
    {
        Gate::authorize('update', $location);

        $updateLocation($location, $request->validated());

        return back();
    }

    public function destroy(string $tenant, Location $location, DeleteLocation $deleteLocation): RedirectResponse
    {
        Gate::authorize('delete', $location);

        $deleteLocation($location);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function locationData(Location $location): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'phone' => $location->phone,
            'sort_order' => $location->sort_order,
            'active' => $location->active,
            'rooms' => $location->rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'location_id' => $room->location_id,
                'name' => $room->name,
                'type' => $room->type->value,
                'capacity' => $room->capacity,
                'description' => $room->description,
                'active' => $room->active,
            ])->values(),
        ];
    }
}
