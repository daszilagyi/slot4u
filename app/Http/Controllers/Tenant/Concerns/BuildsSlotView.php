<?php

namespace App\Http\Controllers\Tenant\Concerns;

use App\Enums\BookingMode;
use App\Models\Location;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Booking\Slot;
use App\Settings\TenantSettings;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Shared slot-picker view + slot re-validation for the public booking page
 * (SLO-30/31/92) and the members-area reschedule flow (SLO-97). Both build the
 * same week strip + free-slot grid and re-derive the authoritative slot from live
 * availability, so a submitted start/end is never trusted verbatim. The using
 * class must expose an `AvailabilityService $availability` property.
 */
trait BuildsSlotView
{
    /**
     * Week strip + the selected day's slots + filter options for a time-slot
     * service.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function slotView(Service $service, array $filters, string $timezone): array
    {
        $today = Carbon::now($timezone)->startOfDay();

        $selected = isset($filters['date'])
            ? Carbon::parse($filters['date'], $timezone)->startOfDay()
            : $today->copy();
        // Never let the picker land on a past day.
        if ($selected->lt($today)) {
            $selected = $today->copy();
        }

        $weekStart = $selected->copy()->startOfWeek(Carbon::MONDAY);
        $week = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            $week[] = [
                'date' => $day->toDateString(),
                'day' => $day->day,
                'weekday' => $day->isoWeekday(),
                'is_today' => $day->isSameDay($today),
                'is_past' => $day->lt($today),
                'is_selected' => $day->isSameDay($selected),
            ];
        }

        $slots = $this->availability->slotsForDay(
            $service,
            $selected->copy(),
            $filters['staff'] ?? null,
            $filters['room'] ?? null,
            $filters['location'] ?? null,
        );

        return [
            'week' => $week,
            'week_start' => $weekStart->toDateString(),
            'selected_date' => $selected->toDateString(),
            'prev_week' => $weekStart->copy()->subDay()->toDateString(),
            'next_week' => $weekStart->copy()->addDays(7)->toDateString(),
            'is_first_week' => $weekStart->lte($today),
            // The grid step the free-range rental duration picker builds options on
            // (SLO-92); harmless for a fixed-duration service that has no picker.
            'slot_interval_minutes' => TenantSettings::fromArray(app(TenantManager::class)->current()->settings)->slotIntervalMinutes,
            'slots' => array_map(fn (Slot $slot) => [
                'start' => $slot->start->toIso8601String(),
                'end' => $slot->end->toIso8601String(),
                'staff_id' => $slot->staffId,
                'room_id' => $slot->roomId,
                'time' => $slot->start->copy()->timezone($timezone)->format('H:i'),
            ], $slots),
            'staff_options' => $service->staff->map(fn (Staff $staff) => [
                'id' => $staff->id,
                'name' => $staff->name,
                'location_ids' => $staff->locations->pluck('id')->values(),
            ])->values(),
            'room_options' => $service->rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'location_id' => $room->location_id,
            ])->values(),
            'location_options' => $this->locationOptions($service),
        ];
    }

    /**
     * The live availability slot matching the submitted start + resource, or a
     * clean "slot unavailable" error (which self-renders back to the picker). The
     * matched slot's own instants are authoritative — the request times are only a
     * lookup key, never stored verbatim.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function matchAvailableSlot(Service $service, array $data): Slot
    {
        // A free-range resource_rental books a caller-chosen length: validate the full
        // chosen range is free, not just the min-length grid slot (SLO-92).
        if ($service->booking_mode === BookingMode::ResourceRental && $service->duration_minutes === null) {
            $slot = $this->availability->matchRentalSlot(
                $service,
                Carbon::parse($data['starts_at']),
                (int) ($data['duration_minutes'] ?? 0),
                isset($data['room_id']) ? (int) $data['room_id'] : null,
            );
            if ($slot === null) {
                throw ValidationException::withMessages(['booking' => __('app.booking.error.slot_unavailable')]);
            }

            return $slot;
        }

        $timezone = app(TenantManager::class)->current()->timezone;
        $start = Carbon::parse($data['starts_at']);
        $staffId = isset($data['staff_id']) ? (int) $data['staff_id'] : null;
        $roomId = isset($data['room_id']) ? (int) $data['room_id'] : null;

        $slots = $this->availability->slotsForDay(
            $service,
            $start->copy()->timezone($timezone),
            $staffId,
            $roomId,
        );

        foreach ($slots as $slot) {
            if ($slot->start->equalTo($start) && $slot->staffId === $staffId && $slot->roomId === $roomId) {
                return $slot;
            }
        }

        throw ValidationException::withMessages([
            'booking' => __('app.booking.error.slot_unavailable'),
        ]);
    }

    /**
     * Distinct locations the service is offered at: its staff's locations
     * (duration_based) and its rooms' locations (resource_rental).
     *
     * @return list<array{id: int, name: string}>
     */
    private function locationOptions(Service $service): array
    {
        $locations = $service->staff
            ->flatMap(fn (Staff $staff) => $staff->locations)
            ->concat($service->rooms->map(fn (Room $room) => $room->location)->filter());

        return $locations
            ->filter(fn (?Location $location) => $location !== null && $location->active)
            ->unique('id')
            ->sortBy('name')
            ->map(fn (Location $location) => ['id' => $location->id, 'name' => $location->name])
            ->values()
            ->all();
    }

    private function localDateTime(?Carbon $instant, string $timezone): ?string
    {
        return $instant?->copy()->timezone($timezone)->format('Y-m-d H:i');
    }
}
