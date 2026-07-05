<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Schedule\CopyScheduleDay;
use App\Actions\Schedule\CreateSchedule;
use App\Actions\Schedule\DeleteSchedule;
use App\Actions\Schedule\UpdateSchedule;
use App\Enums\ScheduleExceptionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CopyScheduleDayRequest;
use App\Http\Requests\Admin\ScheduleRequest;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Staff;
use App\Services\Schedule\FutureScheduleConflicts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly schedule + exception management (SLO-19). Lives behind auth +
 * ensure.user.tenant + can:schedule.manage (routes/tenant.php). The page loads
 * every schedulable resource (staff + rooms) with all of the tenant's bands and
 * exceptions; timing/overlap validation is in ScheduleRequest, the polymorphic
 * writes in the Action layer.
 */
class ScheduleController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Schedule::class);

        $schedules = Schedule::query()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        $exceptions = ScheduleException::query()
            ->orderBy('date')
            ->get();

        return Inertia::render('Admin/Schedule/Index', [
            'schedulables' => $this->schedulables(),
            'locations' => Location::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'schedules' => $schedules->map(fn (Schedule $schedule) => $this->scheduleData($schedule))->values(),
            'exceptions' => $exceptions->map(fn (ScheduleException $exception) => $this->exceptionData($exception))->values(),
            'exceptionTypes' => ScheduleExceptionType::values(),
            // Future-booking conflicts flashed by the last mutation (docs/04): a
            // warning list, shown once, never an automatic deletion. Empty until M3.
            'conflicts' => session('scheduleConflicts', []),
        ]);
    }

    public function store(ScheduleRequest $request, CreateSchedule $createSchedule, FutureScheduleConflicts $conflicts): RedirectResponse
    {
        Gate::authorize('create', Schedule::class);

        $data = $request->validated();
        $createSchedule($data);

        return $this->backWithConflicts($conflicts, $data['schedulable_type'], (int) $data['schedulable_id']);
    }

    // $tenant absorbs the subdomain route parameter so {schedule} binds by id.
    public function update(ScheduleRequest $request, string $tenant, Schedule $schedule, UpdateSchedule $updateSchedule, FutureScheduleConflicts $conflicts): RedirectResponse
    {
        Gate::authorize('update', $schedule);

        $updateSchedule($schedule, $request->validated());

        return $this->backWithConflicts($conflicts, $schedule->schedulable_type, $schedule->schedulable_id);
    }

    public function destroy(string $tenant, Schedule $schedule, DeleteSchedule $deleteSchedule, FutureScheduleConflicts $conflicts): RedirectResponse
    {
        Gate::authorize('delete', $schedule);

        $type = $schedule->schedulable_type;
        $id = $schedule->schedulable_id;
        $deleteSchedule($schedule);

        return $this->backWithConflicts($conflicts, $type, $id);
    }

    public function copyDay(CopyScheduleDayRequest $request, CopyScheduleDay $copyScheduleDay): RedirectResponse
    {
        Gate::authorize('create', Schedule::class);

        $copyScheduleDay($request->validated());

        return back();
    }

    /**
     * Every bookable resource that can carry a schedule (staff + rooms), tagged
     * with its morph alias so the UI groups bands under it. Ordered for a stable
     * picker; N+1-free (rooms eager-load their location name).
     *
     * @return list<array<string, mixed>>
     */
    private function schedulables(): array
    {
        $staff = Staff::query()->with('locations:id')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Staff $member) => [
                'type' => 'staff',
                'id' => $member->id,
                'name' => $member->name,
                'location_name' => null,
                // Assigned locations bound a staff band's location choice (SLO-51).
                'location_ids' => $member->locations->pluck('id')->values(),
            ]);

        $rooms = Room::query()->with('location:id,name')->orderBy('name')->get()
            ->map(fn (Room $room) => [
                'type' => 'room',
                'id' => $room->id,
                'name' => $room->name,
                'location_name' => $room->location?->name,
                // A room band can only be scoped to the room's own location.
                'location_ids' => [$room->location_id],
            ]);

        return $staff->concat($rooms)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleData(Schedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'schedulable_type' => $schedule->schedulable_type,
            'schedulable_id' => $schedule->schedulable_id,
            'location_id' => $schedule->location_id,
            'day_of_week' => $schedule->day_of_week,
            // Trim the stored seconds — the UI works in H:i.
            'start_time' => substr($schedule->start_time, 0, 5),
            'end_time' => substr($schedule->end_time, 0, 5),
            'valid_from' => $schedule->valid_from?->format('Y-m-d'),
            'valid_until' => $schedule->valid_until?->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function exceptionData(ScheduleException $exception): array
    {
        return [
            'id' => $exception->id,
            'schedulable_type' => $exception->schedulable_type,
            'schedulable_id' => $exception->schedulable_id,
            'date' => $exception->date->format('Y-m-d'),
            'start_time' => $exception->start_time !== null ? substr($exception->start_time, 0, 5) : null,
            'end_time' => $exception->end_time !== null ? substr($exception->end_time, 0, 5) : null,
            'type' => $exception->type->value,
            'note' => $exception->note,
        ];
    }

    private function backWithConflicts(FutureScheduleConflicts $conflicts, string $type, int $id): RedirectResponse
    {
        return back()->with('scheduleConflicts', $conflicts->forSchedulable($type, $id));
    }
}
