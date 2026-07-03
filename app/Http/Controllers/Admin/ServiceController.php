<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Service\CreateService;
use App\Actions\Service\DeleteService;
use App\Actions\Service\UpdateService;
use App\Enums\BookingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant service management (SLO-18) — the most involved master data. Lives
 * behind auth + ensure.user.tenant + can:service.manage (routes/tenant.php);
 * mode/feature validation is in ServiceRequest, pivot syncing + normalisation in
 * the Action classes.
 */
class ServiceController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Service::class);

        // Eager-load only the pivot ids the form needs; keeps the render N+1-free.
        $services = Service::query()
            ->with(['staff:id', 'rooms:id'])
            ->orderBy('name')
            ->get();

        $categories = ServiceCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'sort_order']);

        return Inertia::render('Admin/Services/Index', [
            'services' => $services->map(fn (Service $service) => $this->serviceData($service))->values(),
            'categories' => $categories,
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'rooms' => Room::query()->with('location:id,name')->orderBy('name')->get()
                ->map(fn (Room $room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'location_name' => $room->location?->name,
                ])->values(),
            'bookingModes' => BookingMode::values(),
        ]);
    }

    public function store(ServiceRequest $request, CreateService $createService): RedirectResponse
    {
        Gate::authorize('create', Service::class);

        $createService($request->validated());

        return back();
    }

    // $tenant absorbs the subdomain route parameter so {service} binds by name.
    public function update(ServiceRequest $request, string $tenant, Service $service, UpdateService $updateService): RedirectResponse
    {
        Gate::authorize('update', $service);

        $updateService($service, $request->validated());

        return back();
    }

    public function destroy(string $tenant, Service $service, DeleteService $deleteService): RedirectResponse
    {
        Gate::authorize('delete', $service);

        $deleteService($service);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceData(Service $service): array
    {
        return [
            'id' => $service->id,
            'category_id' => $service->category_id,
            'name' => $service->name,
            'description' => $service->description,
            'booking_mode' => $service->booking_mode->value,
            'duration_minutes' => $service->duration_minutes,
            'buffer_before_minutes' => $service->buffer_before_minutes,
            'buffer_after_minutes' => $service->buffer_after_minutes,
            'price_minor' => $service->price_minor,
            'currency' => $service->currency,
            'capacity' => $service->capacity,
            'requires_staff' => $service->requires_staff,
            'requires_room' => $service->requires_room,
            'requires_approval' => $service->requires_approval,
            'waitlist_enabled' => $service->waitlist_enabled,
            'online_payment_required' => $service->online_payment_required,
            'settings' => $service->settings,
            'active' => $service->active,
            'staff_ids' => $service->staff->pluck('id')->values(),
            'room_ids' => $service->rooms->pluck('id')->values(),
        ];
    }
}
