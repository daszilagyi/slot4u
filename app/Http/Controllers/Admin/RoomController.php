<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Room\CreateRoom;
use App\Actions\Room\DeleteRoom;
use App\Actions\Room\UpdateRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoomRequest;
use App\Models\Location;
use App\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Room CRUD nested under a location (SLO-16). The location is a route-bound,
 * tenant-scoped model (cross-tenant → 404); business rules live in the Actions.
 */
class RoomController extends Controller
{
    // $tenant is the subdomain route parameter (docs/01); it is consumed here so
    // the {location}/{room} path parameters bind by name to their models.
    public function store(RoomRequest $request, string $tenant, Location $location, CreateRoom $createRoom): RedirectResponse
    {
        Gate::authorize('create', Room::class);

        $createRoom($location, $request->validated());

        return back();
    }

    public function update(RoomRequest $request, string $tenant, Room $room, UpdateRoom $updateRoom): RedirectResponse
    {
        Gate::authorize('update', $room);

        $updateRoom($room, $request->validated());

        return back();
    }

    public function destroy(string $tenant, Room $room, DeleteRoom $deleteRoom): RedirectResponse
    {
        Gate::authorize('delete', $room);

        $deleteRoom($room);

        return back();
    }
}
