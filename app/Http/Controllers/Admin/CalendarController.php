<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalendarFilterRequest;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Calendar\BookingCalendar;
use App\Services\Calendar\BuildBookingCalendar;
use App\Support\BookingVisibility;
use App\Support\CustomerVisibility;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature as Pennant;

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
                'services' => $this->serviceOptions(),
            ],
            // The quick-booking dialog's customer picker (SLO-136). An optional prop:
            // it costs a query only when the dialog actually asks for it by name, so
            // simply opening the calendar never pays for a roster nobody looked at.
            'customers' => Inertia::optional(
                fn () => $this->customerOptions($actor, $filters['customer_search'] ?? null)
            ),
            // Every card action and the quick-create post to an endpoint that enforces
            // its own permission; these flags only keep the UI honest, so it never
            // offers a button the server is going to refuse (SLO-44, SLO-136).
            'can' => [
                'edit' => (bool) $actor->can(Permission::BookingEdit->value),
                'cancel' => (bool) $actor->can(Permission::BookingCancel->value),
                'create' => (bool) $actor->can(Permission::BookingCreate->value),
                // Approving is behind the feature flag as well as the permission
                // (docs/03), the same pair the approval routes sit behind.
                'approve' => (bool) $actor->can(Permission::BookingApprove->value)
                    && Pennant::active(Feature::ApprovalFlow->value),
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
     * Services for the filter dropdown and the quick-booking dialog (SLO-136).
     *
     * `bookable` is what the dialog offers: clicking an empty slot picks a time, so
     * only the time-slot modes can be booked that way — an event needs its event,
     * and a quote-request service is booked by accepting a quote (docs/04 §6). The
     * duration is sent so the dialog can show the end it is about to create; the end
     * itself is derived server-side, in real elapsed time (docs/01 §7).
     *
     * @return list<array<string, mixed>>
     */
    private function serviceOptions(): array
    {
        return Service::query()
            ->orderBy('name')
            ->get(['id', 'name', 'booking_mode', 'duration_minutes'])
            ->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'booking_mode' => $service->booking_mode->value,
                'duration_minutes' => $service->duration_minutes,
                'bookable' => $service->booking_mode->usesTimeSlot(),
            ])
            ->all();
    }

    /**
     * Customers matching what was typed in the quick-booking dialog, capped at a
     * short list — a picker, not a roster dump. Scoped exactly like the customer
     * page: the tenant's customers, narrowed by {@see CustomerVisibility} so an
     * employee never sees (or books for) a colleague's customer. Without
     * `customer.view` there is no picker at all; a booking may still be created
     * without a customer.
     *
     * @return list<array{id: int, name: string, email: string|null}>
     */
    private function customerOptions(User $actor, ?string $search): array
    {
        if (! $actor->can(Permission::CustomerView->value)) {
            return [];
        }

        $query = Customer::tenantScoped()->orderBy('name');

        if ($search !== null && $search !== '') {
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%'));
        }

        CustomerVisibility::apply($query, $actor);

        return $query->limit(20)->get(['id', 'name', 'email'])
            ->map(fn (Customer $customer): array => [
                'id' => (int) $customer->getKey(),
                'name' => (string) $customer->name,
                'email' => $customer->email,
            ])
            ->all();
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
