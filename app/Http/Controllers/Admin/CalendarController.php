<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalendarFilterRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Calendar\BookingCalendar;
use App\Services\Calendar\BuildBookingCalendar;
use App\Support\BookingVisibility;
use App\Tenancy\TenantManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin calendar (SLO-44, docs/05 M7): the tenant's bookings on a day or week time
 * grid, split into resource columns. Behind auth + ensure.user.tenant +
 * can:booking.view (routes/tenant.php); the grid itself is scoped by
 * {@see BookingVisibility}, so an employee sees only their own — same rule as the
 * list page. All the placing arithmetic lives in {@see BuildBookingCalendar}.
 */
class CalendarController extends Controller
{
    public function index(CalendarFilterRequest $request, BuildBookingCalendar $build): Response
    {
        Gate::authorize('viewAny', Booking::class);

        $tenant = app(TenantManager::class)->current();
        abort_if($tenant === null, 404);

        $actor = $request->user();
        /** @var array<string, mixed> $filters */
        $filters = $request->validated();

        return Inertia::render('Admin/Calendar/Index', [
            'calendar' => $this->props($build($tenant, $actor, $filters)),
            'filters' => [
                'view' => $filters['view'] ?? 'week',
                'group' => $filters['group'] ?? 'staff',
                'date' => $filters['date'] ?? null,
                'staff_id' => isset($filters['staff_id']) ? (int) $filters['staff_id'] : null,
                'room_id' => isset($filters['room_id']) ? (int) $filters['room_id'] : null,
                'service_id' => isset($filters['service_id']) ? (int) $filters['service_id'] : null,
            ],
            'options' => [
                // Mirrors the grid's own scope: an employee filters over their own
                // staff records only, so the dropdown can't hint at colleagues.
                'staff' => $this->staffOptions($actor),
                'rooms' => Room::query()->orderBy('name')->get(['id', 'name']),
                'services' => Service::query()->orderBy('name')->get(['id', 'name']),
            ],
            // Drag-and-drop rescheduling posts to the booking.edit endpoint, so a
            // view-only actor must not be offered the drag at all (SLO-44). The
            // endpoint enforces it regardless — this only keeps the UI honest.
            'can' => [
                'edit' => (bool) $actor->can(Permission::BookingEdit->value),
            ],
        ]);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    private function staffOptions(User $actor): Collection
    {
        $query = Staff::query()->orderBy('name');

        if (! BookingVisibility::unrestricted($actor)) {
            $query->whereIn('id', BookingVisibility::actorStaffIds($actor));
        }

        return $query->get(['id', 'name'])->map(fn (Staff $staff) => [
            'id' => $staff->id,
            'name' => $staff->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(BookingCalendar $calendar): array
    {
        return [
            'view' => $calendar->view,
            'date' => $calendar->date,
            'range_start' => $calendar->rangeStart,
            'range_end' => $calendar->rangeEnd,
            'prev_date' => $calendar->prevDate,
            'next_date' => $calendar->nextDate,
            'today' => $calendar->today,
            'timezone' => $calendar->timezone,
            'window_start_minute' => $calendar->windowStartMinute,
            'window_end_minute' => $calendar->windowEndMinute,
            'columns' => $calendar->columns,
            'events' => $calendar->events,
            'unscheduled' => $calendar->unscheduled,
        ];
    }
}
