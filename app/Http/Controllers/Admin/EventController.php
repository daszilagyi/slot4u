<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Event\CancelEvent;
use App\Actions\Event\CreateEvent;
use App\Actions\Event\DeleteEvent;
use App\Actions\Event\UpdateEvent;
use App\Enums\BookingMode;
use App\Enums\Feature;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventRequest;
use App\Models\Event;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature as Pennant;

/**
 * Announced events for event_based services (SLO-20). Lives behind
 * auth + ensure.user.tenant + can:schedule.manage (routes/tenant.php). Times are
 * stored UTC and rendered in the tenant timezone (docs/01 §7); recurrence,
 * conflict and capacity rules live in EventRequest + the Action classes.
 */
class EventController extends Controller
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function index(): Response
    {
        Gate::authorize('viewAny', Event::class);

        $events = Event::query()
            ->with(['service:id,name', 'staff:id,name', 'room:id,name'])
            ->orderBy('starts_at')
            ->get();

        return Inertia::render('Admin/Events/Index', [
            'events' => $events->map(fn (Event $event) => $this->eventData($event))->values(),
            // Only event_based services can carry an announced occurrence.
            'services' => Service::query()
                ->where('booking_mode', BookingMode::EventBased->value)
                ->orderBy('name')
                ->get(['id', 'name']),
            'staff' => Staff::query()->orderBy('name')->get(['id', 'name']),
            'rooms' => Room::query()->orderBy('name')->get(['id', 'name']),
            'waitlistEnabled' => Pennant::active(Feature::Waitlist->value),
            'timezone' => $this->timezone(),
        ]);
    }

    public function store(EventRequest $request, CreateEvent $createEvent): RedirectResponse
    {
        Gate::authorize('create', Event::class);

        $createEvent($request->validated());

        return back();
    }

    // $tenant absorbs the subdomain route parameter so {event} binds by id.
    public function update(EventRequest $request, string $tenant, Event $event, UpdateEvent $updateEvent): RedirectResponse
    {
        Gate::authorize('update', $event);

        $updateEvent($event, $request->validated(), $request->input('scope') === 'following');

        return back();
    }

    public function destroy(Request $request, string $tenant, Event $event, DeleteEvent $deleteEvent): RedirectResponse
    {
        Gate::authorize('delete', $event);

        $deleteEvent($event, $this->appliesToFollowing($request));

        return back();
    }

    public function cancel(Request $request, string $tenant, Event $event, CancelEvent $cancelEvent): RedirectResponse
    {
        Gate::authorize('update', $event);

        $cancelEvent($event, $this->appliesToFollowing($request));

        return back();
    }

    /**
     * Validate + read the this/following scope for a series operation.
     */
    private function appliesToFollowing(Request $request): bool
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['this', 'following'])],
        ]);

        return ($validated['scope'] ?? 'this') === 'following';
    }

    /**
     * @return array<string, mixed>
     */
    private function eventData(Event $event): array
    {
        $timezone = $this->timezone();

        return [
            'id' => $event->id,
            'service_id' => $event->service_id,
            'service_name' => $event->service?->name,
            'staff_id' => $event->staff_id,
            'staff_name' => $event->staff?->name,
            'room_id' => $event->room_id,
            'room_name' => $event->room?->name,
            // Tenant-local wall-clock for the datetime-local input (docs/01 §7).
            'starts_at' => $event->starts_at->timezone($timezone)->format('Y-m-d\TH:i'),
            'ends_at' => $event->ends_at->timezone($timezone)->format('Y-m-d\TH:i'),
            'capacity' => $event->capacity,
            'booked_count' => $event->booked_count,
            'waitlist_enabled' => $event->waitlist_enabled,
            'status' => $event->status->value,
            'is_recurring' => $event->series_id !== null,
        ];
    }

    private function timezone(): string
    {
        $tenant = $this->tenants->current();

        return $tenant !== null ? $tenant->timezone : (string) config('app.timezone');
    }
}
