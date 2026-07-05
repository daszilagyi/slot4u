<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Booking\AvailabilityService;
use Illuminate\Support\Carbon;

/** Local start times ("H:i") of the produced slots, for readable assertions. */
function slotTimes(array $slots, string $timezone = 'UTC'): array
{
    return array_map(
        fn ($slot) => $slot->start->copy()->timezone($timezone)->format('H:i'),
        $slots,
    );
}

function availabilityService(): AvailabilityService
{
    return app(AvailabilityService::class);
}

/** A duration_based service with one assigned staff and a weekday band. */
function durationScenario(array $serviceOverrides = [], string $tz = 'UTC', string $band = '09:00-12:00'): array
{
    $tenant = Tenant::factory()->active()->create([
        'timezone' => $tz,
        'settings' => ['slot_interval_minutes' => 30],
    ]);
    $service = Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_staff' => true,
    ], $serviceOverrides));
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);

    // 2026-09-07 is a Monday; band on that weekday.
    $date = Carbon::parse('2026-09-07', $tz);
    [$start, $end] = explode('-', $band);
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay($date->isoWeekday(), $start, $end)->create();

    return [$tenant, $service, $staff, $date];
}

it('generates duration+interval grid slots within the band', function () {
    [, $service, , $date] = durationScenario();

    $slots = availabilityService()->slotsForDay($service, $date);

    // 60-min service on a 30-min grid, 09:00–12:00 → last fitting start is 11:00.
    expect(slotTimes($slots))->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);
});

it('excludes slots overlapping an existing booking', function () {
    [$tenant, $service, $staff, $date] = durationScenario();
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 11:00:00',
    ]);

    $slots = availabilityService()->slotsForDay($service, $date);

    // 09:30/10:00/10:30 all overlap 10:00–11:00 and drop out.
    expect(slotTimes($slots))->toBe(['09:00', '11:00']);
});

it('ignores a canceled booking', function () {
    [$tenant, $service, $staff, $date] = durationScenario();
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Canceled)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 10:00:00',
        'ends_at' => '2026-09-07 11:00:00',
    ]);

    $slots = availabilityService()->slotsForDay($service, $date);

    expect(slotTimes($slots))->toContain('10:00');
});

it('offers the opening slot even when its buffer overruns the band edge', function () {
    // buffer_before 15m: the 09:00 slot's occupied time starts 08:45 (before the
    // 09:00 opening), but with no booking there it must still be offered.
    [, $service, , $date] = durationScenario(['buffer_before_minutes' => 15]);

    $slots = availabilityService()->slotsForDay($service, $date);

    expect(slotTimes($slots))->toContain('09:00');
});

it('keeps a buffer gap from an existing booking', function () {
    [$tenant, $service, $staff, $date] = durationScenario(['buffer_after_minutes' => 30]);
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 09:00:00',
        'ends_at' => '2026-09-07 10:00:00',
    ]);

    $slots = availabilityService()->slotsForDay($service, $date);

    // With a 30-min after-buffer, the 10:00 start is too close to the 09:00–10:00
    // booking; the next free start is 10:30.
    expect(slotTimes($slots))->not->toContain('10:00')
        ->and(slotTimes($slots))->toContain('10:30');
});

it('drops all slots on a whole-day off exception', function () {
    [$tenant, $service, $staff, $date] = durationScenario();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->create([
        'date' => $date->toDateString(),
        'start_time' => null,
        'end_time' => null,
        'type' => ScheduleExceptionType::Off,
    ]);

    expect(availabilityService()->slotsForDay($service, $date))->toBe([]);
});

it('cuts a partial off exception out of the band', function () {
    [$tenant, $service, $staff, $date] = durationScenario();
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->create([
        'date' => $date->toDateString(),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'type' => ScheduleExceptionType::Off,
    ]);

    $slots = availabilityService()->slotsForDay($service, $date);

    // 10:00/10:30 fall inside the cut; a 60-min slot can't fit 09:30 (ends 10:30
    // > cut start) nor 11:00→12:00 only.
    expect(slotTimes($slots))->toBe(['09:00', '11:00']);
});

it('opens slots from an extra opening exception', function () {
    [$tenant, $service, $staff, $date] = durationScenario(band: '09:00-10:00');
    ScheduleException::factory()->forTenant($tenant)->forSchedulable($staff)->extra()->create([
        'date' => $date->toDateString(),
        'start_time' => '14:00',
        'end_time' => '16:00',
        'type' => ScheduleExceptionType::Extra,
    ]);

    $slots = availabilityService()->slotsForDay($service, $date);

    expect(slotTimes($slots))->toContain('14:00')
        ->and(slotTimes($slots))->toContain('15:00');
});

it('anchors slot UTC instants to the tenant timezone across DST', function () {
    // Europe/Budapest is CEST (+2) in September; a 10:00 local band start is 08:00
    // UTC, proving the wall-clock grid is resolved in the tenant tz (docs/01 §7).
    [, $service, , $date] = durationScenario(tz: 'Europe/Budapest', band: '10:00-11:00');

    $slots = availabilityService()->slotsForDay($service, $date);

    expect($slots)->toHaveCount(1)
        ->and($slots[0]->start->utc()->format('Y-m-d H:i'))->toBe('2026-09-07 08:00')
        ->and($slots[0]->start->copy()->timezone('Europe/Budapest')->format('H:i'))->toBe('10:00');
});

it('unions the slots of all assigned staff for the "anyone" option', function () {
    $tenant = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_staff' => true,
    ]);
    $date = Carbon::parse('2026-09-07', 'UTC');
    $a = Staff::factory()->forTenant($tenant)->create();
    $b = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach([$a->id, $b->id]);
    Schedule::factory()->forTenant($tenant)->forSchedulable($a)->onDay($date->isoWeekday(), '09:00', '10:30')->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($b)->onDay($date->isoWeekday(), '11:00', '13:00')->create();

    $slots = availabilityService()->slotsForDay($service, $date);

    // A covers 09:00/09:30; B covers 11:00/11:30/12:00 — a union, deduped by start.
    expect(slotTimes($slots))->toBe(['09:00', '09:30', '11:00', '11:30', '12:00']);
});

it('filters to a single staff when one is requested', function () {
    $tenant = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_staff' => true,
    ]);
    $date = Carbon::parse('2026-09-07', 'UTC');
    $a = Staff::factory()->forTenant($tenant)->create();
    $b = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach([$a->id, $b->id]);
    Schedule::factory()->forTenant($tenant)->forSchedulable($a)->onDay($date->isoWeekday(), '09:00', '10:30')->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($b)->onDay($date->isoWeekday(), '11:00', '13:00')->create();

    $slots = availabilityService()->slotsForDay($service, $date, staffId: $a->id);

    expect(slotTimes($slots))->toBe(['09:00', '09:30'])
        ->and($slots[0]->staffId)->toBe($a->id);
});

it('generates room slots for a resource_rental service', function () {
    $tenant = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_room' => true,
    ]);
    $date = Carbon::parse('2026-09-07', 'UTC');
    $room = Room::factory()->forTenant($tenant)->create();
    $service->rooms()->attach($room->id);
    Schedule::factory()->forTenant($tenant)->forSchedulable($room)->onDay($date->isoWeekday(), '08:00', '10:00')->create();

    $slots = availabilityService()->slotsForDay($service, $date);

    expect(slotTimes($slots))->toBe(['08:00', '08:30', '09:00'])
        ->and($slots[0]->roomId)->toBe($room->id);
});

it('validates a free rental duration against the min/max bounds', function () {
    $tenant = Tenant::factory()->active()->create();
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::ResourceRental,
        'duration_minutes' => null,
        'settings' => ['min_duration_minutes' => 30, 'max_duration_minutes' => 120],
    ]);

    $availability = availabilityService();
    expect($availability->isDurationAllowed($service, 60))->toBeTrue()
        ->and($availability->isDurationAllowed($service, 30))->toBeTrue()
        ->and($availability->isDurationAllowed($service, 120))->toBeTrue()
        ->and($availability->isDurationAllowed($service, 15))->toBeFalse()
        ->and($availability->isDurationAllowed($service, 150))->toBeFalse();
});

it('returns nothing for a non-timeslot booking mode', function () {
    $tenant = Tenant::factory()->active()->create();
    $service = Service::factory()->forTenant($tenant)->create(['booking_mode' => BookingMode::EventBased]);

    expect(availabilityService()->slotsForDay($service, Carbon::parse('2026-09-07')))->toBe([]);
});

it('never leaks another tenant\'s resource for a forged staff id', function () {
    [, $service, , $date] = durationScenario();
    // A staff belonging to a DIFFERENT tenant, with a full schedule.
    $other = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $foreignStaff = Staff::factory()->forTenant($other)->create();
    Schedule::factory()->forTenant($other)->forSchedulable($foreignStaff)
        ->onDay($date->isoWeekday(), '09:00', '17:00')->create();

    // Requesting the foreign staff on our service must yield nothing (not that
    // staff's schedule) — the id is not one of the service's assigned staff.
    expect(availabilityService()->slotsForDay($service, $date, staffId: $foreignStaff->id))->toBe([]);
});

it('does not count another tenant booking or schedule (isolation)', function () {
    [$tenant, $service, $staff, $date] = durationScenario();
    // Another tenant with a same-id-space staff and a booking must not affect us.
    $other = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $otherStaff = Staff::factory()->forTenant($other)->create();
    Booking::factory()->forTenant($other)->status(BookingStatus::Confirmed)->create([
        'staff_id' => $otherStaff->id,
        'starts_at' => '2026-09-07 09:00:00',
        'ends_at' => '2026-09-07 12:00:00',
    ]);

    // Our staff's full 09:00–12:00 availability is unaffected by the other tenant.
    expect(slotTimes(availabilityService()->slotsForDay($service, $date)))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);
});

it('returns nothing for a same-tenant staff not assigned to the service', function () {
    [$tenant, $service, , $date] = durationScenario();
    // A staff in the SAME tenant but not attached to this service.
    $unassigned = Staff::factory()->forTenant($tenant)->create();
    Schedule::factory()->forTenant($tenant)->forSchedulable($unassigned)
        ->onDay($date->isoWeekday(), '09:00', '17:00')->create();

    expect(availabilityService()->slotsForDay($service, $date, staffId: $unassigned->id))->toBe([]);
});

it('filters bands to the requested location for a multi-location staff', function () {
    $tenant = Tenant::factory()->active()->create(['timezone' => 'UTC', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_staff' => true,
    ]);
    $date = Carbon::parse('2026-09-07', 'UTC');
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);
    $locA = Location::factory()->forTenant($tenant)->create();
    $locB = Location::factory()->forTenant($tenant)->create();
    $staff->locations()->sync([$locA->id, $locB->id]);
    // Same weekday: 09–11 at A, 14–16 at B.
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay($date->isoWeekday(), '09:00', '11:00')->create(['location_id' => $locA->id]);
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay($date->isoWeekday(), '14:00', '16:00')->create(['location_id' => $locB->id]);

    // Requesting location A must exclude the B band.
    expect(slotTimes(availabilityService()->slotsForDay($service, $date, locationId: $locA->id)))
        ->toBe(['09:00', '09:30', '10:00']);
    // No location = union of both.
    expect(slotTimes(availabilityService()->slotsForDay($service, $date)))
        ->toBe(['09:00', '09:30', '10:00', '14:00', '14:30', '15:00']);
});

it('resolves the wall-clock grid across the spring-forward DST transition', function () {
    // 2026-03-29 Europe/Budapest springs forward (02:00 → 03:00). A 10:00 local
    // band start is CEST (+2) → 08:00 UTC that day.
    $tenant = Tenant::factory()->active()->create(['timezone' => 'Europe/Budapest', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_staff' => true,
    ]);
    $date = Carbon::parse('2026-03-29', 'Europe/Budapest');
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay($date->isoWeekday(), '10:00', '11:00')->create();

    $slots = availabilityService()->slotsForDay($service, $date);

    expect($slots)->toHaveCount(1)
        ->and($slots[0]->start->utc()->format('H:i'))->toBe('08:00');
});

it('resolves the wall-clock grid across the fall-back DST transition', function () {
    // 2026-10-25 Europe/Budapest falls back (CEST +2 → CET +1). A 10:00 local band
    // start is already CET (+1) at 10:00 → 09:00 UTC.
    $tenant = Tenant::factory()->active()->create(['timezone' => 'Europe/Budapest', 'settings' => ['slot_interval_minutes' => 30]]);
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased, 'duration_minutes' => 60,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'requires_staff' => true,
    ]);
    $date = Carbon::parse('2026-10-25', 'Europe/Budapest');
    $staff = Staff::factory()->forTenant($tenant)->create();
    $service->staff()->attach($staff->id);
    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)
        ->onDay($date->isoWeekday(), '10:00', '11:00')->create();

    $slots = availabilityService()->slotsForDay($service, $date);

    expect($slots)->toHaveCount(1)
        ->and($slots[0]->start->utc()->format('H:i'))->toBe('09:00');
});
