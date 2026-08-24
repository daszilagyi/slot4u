<?php

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Http\Requests\Concerns\ScopesSchedulable;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Staff;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating/updating a weekly schedule band (SLO-19). A band lives
 * within a single day: end_time must be strictly after start_time (midnight
 * crossing is explicitly forbidden — docs/04 edge case). Bands for the same
 * resource on the same weekday may not overlap while their validity windows
 * overlap. The schedulable + location references are constrained to the current
 * tenant so a forged foreign id cannot slip past validation before the scope 404s.
 */
class ScheduleRequest extends FormRequest
{
    use ScopesSchedulable;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::ScheduleManage->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->id();
        $type = (string) $this->input('schedulable_type');
        // Map the morph alias to its backing table for the tenant-scoped exists rule.
        $table = $type === 'room' ? 'rooms' : 'staff';

        return [
            'schedulable_type' => ['required', Rule::in(['staff', 'room'])],
            'schedulable_id' => [
                'required',
                'integer',
                Rule::exists($table, 'id')->where('tenant_id', $tenantId),
            ],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')->where('tenant_id', $tenantId),
            ],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
        ];
    }

    /**
     * Reject a band that overlaps an existing one for the same resource on the
     * same weekday while their validity windows also overlap.
     *
     * The scope check runs first and on the SUBMITTED schedulable, not only the
     * bound one: an update names its target in the body, so without it an actor
     * could take a band they legitimately own and move it onto a colleague
     * (SLO-177).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateSchedulableScope($validator);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateLocationScope($validator);
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = (string) $this->input('start_time');
            $end = (string) $this->input('end_time');
            $from = $this->input('valid_from');
            $until = $this->input('valid_until');

            $current = $this->route('schedule');
            $currentId = $current instanceof Schedule ? $current->getKey() : null;

            $existing = Schedule::query()
                ->where('schedulable_type', $this->input('schedulable_type'))
                ->where('schedulable_id', $this->input('schedulable_id'))
                ->where('day_of_week', $this->integer('day_of_week'))
                ->when($currentId !== null, fn ($q) => $q->whereKeyNot($currentId))
                ->get();

            foreach ($existing as $band) {
                // Times overlap when each starts before the other ends. Compare on
                // H:i to ignore the stored seconds component.
                $timesOverlap = $start < substr((string) $band->end_time, 0, 5)
                    && substr((string) $band->start_time, 0, 5) < $end;

                $bandFrom = $band->valid_from !== null ? $band->valid_from->format('Y-m-d') : null;
                $bandUntil = $band->valid_until !== null ? $band->valid_until->format('Y-m-d') : null;

                if ($timesOverlap && $this->windowsOverlap($from, $until, $bandFrom, $bandUntil)) {
                    $validator->errors()->add('start_time', __('app.admin.schedule.error.overlap'));

                    return;
                }
            }
        });
    }

    /**
     * A location-scoped band (SLO-51) must reference a location the resource
     * actually belongs to: for staff, one of its assigned locations; for a room,
     * its own location. A null location_id means "all of the resource's
     * locations" and is always allowed.
     */
    private function validateLocationScope(Validator $validator): void
    {
        $locationId = $this->input('location_id');
        if ($locationId === null || $locationId === '') {
            return;
        }

        $locationId = (int) $locationId;
        $type = (string) $this->input('schedulable_type');
        $schedulableId = $this->integer('schedulable_id');

        if ($type === 'room') {
            $room = Room::find($schedulableId);
            if ($room !== null && $room->location_id !== $locationId) {
                $validator->errors()->add('location_id', __('app.admin.schedule.error.room_location'));
            }

            return;
        }

        $staff = Staff::with('locations:id')->find($schedulableId);
        if ($staff !== null && ! $staff->locations->contains('id', $locationId)) {
            $validator->errors()->add('location_id', __('app.admin.schedule.error.location_unassigned'));
        }
    }

    /**
     * Whether two closed/open-ended date windows overlap. A null bound is
     * unbounded on that side.
     */
    private function windowsOverlap(?string $fromA, ?string $untilA, ?string $fromB, ?string $untilB): bool
    {
        // Disjoint only if one window ends strictly before the other begins.
        if ($untilA !== null && $fromB !== null && $untilA < $fromB) {
            return false;
        }

        if ($untilB !== null && $fromA !== null && $untilB < $fromA) {
            return false;
        }

        return true;
    }
}
