<?php

use App\Actions\Booking\CreateBooking;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Exceptions\SlotUnavailableException;
use App\Models\Booking;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Booking\AvailabilityService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Automatic room assignment (SLO-200, docs/04 §2)
|--------------------------------------------------------------------------
|
| `requires_room` used to be a setting a tenant could switch on and receive
| nothing for. A duration_based grid was built from the staff schedule alone,
| and the room entered the calculation only when the CALLER had already picked
| one — which a visitor never does. A booking then landed with `room_id = null`,
| and the conflict check bails out early on a resourceless booking, so two
| therapists could be given the same treatment room for the same hour with
| nothing on any screen to say so.
|
| What is pinned here is the pair that fixes it: the grid must not offer a slot
| no room can host, and the booking must come out holding a real room.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A duration-based service that needs a room, with $rooms rooms and $staff staff. */
function roomAssignWorld(int $rooms = 2, int $staff = 2, array $serviceOverrides = []): array
{
    $tenant = Tenant::factory()->active()->create(['timezone' => 'Europe/Budapest']);
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create(array_merge([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'requires_staff' => true,
        'requires_room' => true,
        'requires_approval' => false,
        'online_payment_required' => false,
        'active' => true,
    ], $serviceOverrides));

    $staffMembers = [];
    for ($i = 0; $i < $staff; $i++) {
        $staffMembers[] = Staff::factory()->forTenant($tenant)->create();
    }

    $roomModels = [];
    for ($i = 0; $i < $rooms; $i++) {
        $roomModels[] = Room::factory()->forTenant($tenant)->create(['capacity' => 1]);
    }

    $service->staff()->sync(collect($staffMembers)->pluck('id')->all());
    $service->rooms()->sync(collect($roomModels)->pluck('id')->all());

    return [$tenant, $service->fresh(['staff', 'rooms']), $staffMembers, $roomModels];
}

/** Mon–Fri 09:00–17:00 for a staff member or a room. */
function roomAssignHours(Tenant $tenant, $resource, array $days = [1, 2, 3, 4, 5], string $from = '09:00', string $to = '17:00'): void
{
    foreach ($days as $day) {
        Schedule::factory()->forTenant($tenant)->forSchedulable($resource)->onDay($day, $from, $to)->create();
    }
}

/** The next Wednesday, so a fixed weekday's hours always apply. */
function roomAssignDay(): Carbon
{
    return Carbon::today('Europe/Budapest')->next(Carbon::WEDNESDAY);
}

function roomAssignSlot(Staff $staff, Carbon $day, string $time = '10:00', int $minutes = 60): array
{
    $start = $day->copy()->setTimeFromTimeString($time)->utc();

    return [
        'staff_id' => $staff->getKey(),
        'starts_at' => $start->toDateTimeString(),
        'ends_at' => $start->copy()->addMinutes($minutes)->toDateTimeString(),
        'party_size' => 1,
        'source' => 'online',
    ];
}

// --- Booking time: a room is actually handed out ---------------------------

it('gives the booking a room even though nobody asked for one', function () {
    [, $service, $staff, $rooms] = roomAssignWorld();

    $booking = app(CreateBooking::class)($service, roomAssignSlot($staff[0], roomAssignDay()));

    // ⚠️ THE bug. This used to be null, and a null room is a room nothing
    // defends: hasResourceConflict() returns false before it looks at anything.
    expect($booking->room_id)->not->toBeNull()
        ->and($booking->room_id)->toBe($rooms[0]->getKey());
});

it('gives two staff working the same hour two different rooms', function () {
    [, $service, $staff, $rooms] = roomAssignWorld(rooms: 2, staff: 2);
    $day = roomAssignDay();

    $first = app(CreateBooking::class)($service, roomAssignSlot($staff[0], $day));
    $second = app(CreateBooking::class)($service, roomAssignSlot($staff[1], $day));

    expect($first->room_id)->toBe($rooms[0]->getKey())
        ->and($second->room_id)->toBe($rooms[1]->getKey())
        ->and($first->room_id)->not->toBe($second->room_id);
});

it('refuses the third booking when only two rooms exist', function () {
    [$tenant, $service, $staff] = roomAssignWorld(rooms: 2, staff: 3);
    $day = roomAssignDay();

    app(CreateBooking::class)($service, roomAssignSlot($staff[0], $day));
    app(CreateBooking::class)($service, roomAssignSlot($staff[1], $day));

    // The third therapist is free; the building is not.
    expect(fn () => app(CreateBooking::class)($service, roomAssignSlot($staff[2], $day)))
        ->toThrow(SlotUnavailableException::class);

    // ⚠️ And it left nothing behind. Before SLO-200 this booking was created
    // happily with room_id = null — the worst outcome, because the double-booked
    // room only shows up when two customers arrive at the same door.
    expect(Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())->toBe(2);
});

it('takes the room a cancelled booking gave back', function () {
    [, $service, $staff, $rooms] = roomAssignWorld(rooms: 1, staff: 2);
    $day = roomAssignDay();

    $first = app(CreateBooking::class)($service, roomAssignSlot($staff[0], $day));
    $first->status = BookingStatus::Canceled;
    $first->saveQuietly();

    // A cancelled booking is not occupying, so the one room is free again.
    $second = app(CreateBooking::class)($service, roomAssignSlot($staff[1], $day));

    expect($second->room_id)->toBe($rooms[0]->getKey());
});

it('leaves a pinned room exactly where the caller put it', function () {
    [, $service, $staff, $rooms] = roomAssignWorld(rooms: 3, staff: 1);

    $booking = app(CreateBooking::class)($service, roomAssignSlot($staff[0], roomAssignDay()) + [
        'room_id' => $rooms[2]->getKey(),
    ]);

    // Assignment only fills a gap; it never overrides a decision.
    expect($booking->room_id)->toBe($rooms[2]->getKey());
});

it('assigns nothing when the service does not need a room', function () {
    [, $service, $staff] = roomAssignWorld(rooms: 2, staff: 1, serviceOverrides: ['requires_room' => false]);

    $booking = app(CreateBooking::class)($service, roomAssignSlot($staff[0], roomAssignDay()));

    // Backwards compatibility: a service with rooms attached but `requires_room`
    // off is the old behaviour, and every existing tenant is on it.
    expect($booking->room_id)->toBeNull();
});

it('⚠️ never assigns another tenant a room', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 1, staff: 1);

    // A room belonging to somebody else, attached to nothing.
    $stranger = Tenant::factory()->active()->create();
    $foreign = Room::factory()->forTenant($stranger)->create();

    // Occupy the tenant's own room, so assignment has to look further — and the
    // only thing further is a room it must never reach.
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'room_id' => $rooms[0]->getKey(),
        'starts_at' => roomAssignDay()->copy()->setTimeFromTimeString('10:00')->utc(),
        'ends_at' => roomAssignDay()->copy()->setTimeFromTimeString('11:00')->utc(),
    ]);

    expect(fn () => app(CreateBooking::class)($service, roomAssignSlot($staff[0], roomAssignDay())))
        ->toThrow(SlotUnavailableException::class);

    expect(Booking::withoutGlobalScopes()->where('room_id', $foreign->getKey())->count())->toBe(0);
});

it('skips a deactivated room', function () {
    [, $service, $staff, $rooms] = roomAssignWorld(rooms: 2, staff: 1);
    $rooms[0]->update(['active' => false]);

    $booking = app(CreateBooking::class)($service, roomAssignSlot($staff[0], roomAssignDay()));

    expect($booking->room_id)->toBe($rooms[1]->getKey());
});

// --- Grid time: the slot is not offered if no room can host it -------------

it('stops offering a slot once every room is busy', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 1, staff: 2);
    $day = roomAssignDay();

    foreach ($staff as $member) {
        roomAssignHours($tenant, $member);
    }
    roomAssignHours($tenant, $rooms[0]);

    $slotsBefore = app(AvailabilityService::class)->slotsForDay($service, $day);
    expect($slotsBefore)->not->toBeEmpty();

    // One booking takes the only room for 10:00.
    app(CreateBooking::class)($service, roomAssignSlot($staff[0], $day));

    $starts = collect(app(AvailabilityService::class)->slotsForDay($service, $day))
        ->map(fn ($slot): string => $slot->start->copy()->timezone('Europe/Budapest')->format('H:i'))
        ->all();

    // ⚠️ The second staff member is free at 10:00 and used to be offered — the
    // grid had no idea the room was gone. Other hours are still fine.
    expect($starts)->not->toContain('10:00')
        ->and($starts)->toContain('11:00');
});

it('⚠️ hides a slot when the room is shut, even though the staff is in', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 1, staff: 1);
    $day = roomAssignDay();

    // The therapist works until 17:00; the room closes at 12:00.
    roomAssignHours($tenant, $staff[0], from: '09:00', to: '17:00');
    roomAssignHours($tenant, $rooms[0], from: '09:00', to: '12:00');

    $starts = collect(app(AvailabilityService::class)->slotsForDay($service, $day))
        ->map(fn ($slot): string => $slot->start->copy()->timezone('Europe/Budapest')->format('H:i'))
        ->all();

    expect($starts)->toContain('09:00')
        ->and($starts)->toContain('11:00')
        // 12:00 would run to 13:00, past the room's closing time.
        ->and($starts)->not->toContain('12:00')
        ->and($starts)->not->toContain('15:00');
});

it('⚠️ counts a room booked by another staff member as busy', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 1, staff: 2);
    $day = roomAssignDay();

    roomAssignHours($tenant, $staff[0]);
    roomAssignHours($tenant, $staff[1]);
    roomAssignHours($tenant, $rooms[0]);

    // Someone else's booking holds the room — not this staff member's, so the
    // staff-indexed booking load never saw it. The pinned-room path had the same
    // hole: it loaded the room's opening hours and not its bookings.
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->getKey(),
        'staff_id' => $staff[1]->getKey(),
        'room_id' => $rooms[0]->getKey(),
        'starts_at' => $day->copy()->setTimeFromTimeString('14:00')->utc(),
        'ends_at' => $day->copy()->setTimeFromTimeString('15:00')->utc(),
    ]);

    $pinned = collect(app(AvailabilityService::class)->slotsForDay($service, $day, $staff[0]->getKey(), $rooms[0]->getKey()))
        ->map(fn ($slot): string => $slot->start->copy()->timezone('Europe/Budapest')->format('H:i'))
        ->all();

    $anyone = collect(app(AvailabilityService::class)->slotsForDay($service, $day))
        ->map(fn ($slot): string => $slot->start->copy()->timezone('Europe/Budapest')->format('H:i'))
        ->all();

    expect($pinned)->not->toContain('14:00')
        ->and($anyone)->not->toContain('14:00')
        ->and($pinned)->toContain('10:00');
});

it('offers nothing at all when the service needs a room and has none', function () {
    [$tenant, $service, $staff] = roomAssignWorld(rooms: 0, staff: 1);
    roomAssignHours($tenant, $staff[0]);

    // `requires_room` with no rooms is not "unconstrained" — it is a service
    // nobody can deliver. Offering the staff member's whole day would be a
    // promise the tenant cannot keep.
    expect(app(AvailabilityService::class)->slotsForDay($service, roomAssignDay()))->toBeEmpty();
});

it('leaves a room-free service exactly as it was', function () {
    [$tenant, $service, $staff] = roomAssignWorld(rooms: 0, staff: 1, serviceOverrides: ['requires_room' => false]);
    roomAssignHours($tenant, $staff[0]);

    // The regression guard for every existing tenant: no rooms, no requirement,
    // a full day of slots exactly as before.
    expect(app(AvailabilityService::class)->slotsForDay($service, roomAssignDay()))->not->toBeEmpty();
});

it('refuses on the staff clash without quietly consuming a room', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 2, staff: 1);
    $day = roomAssignDay();

    // The therapist is already busy; both rooms are free.
    Booking::factory()->forTenant($tenant)->status(BookingStatus::Confirmed)->create([
        'service_id' => $service->getKey(),
        'staff_id' => $staff[0]->getKey(),
        'starts_at' => $day->copy()->setTimeFromTimeString('10:30')->utc(),
        'ends_at' => $day->copy()->setTimeFromTimeString('11:30')->utc(),
    ]);

    expect(fn () => app(CreateBooking::class)($service, roomAssignSlot($staff[0], $day)))
        ->toThrow(SlotUnavailableException::class);

    // The rooms are untouched — assignment happens inside the transaction that
    // then rolls back, so a refused booking leaves no room half-claimed.
    foreach ($rooms as $room) {
        expect(Booking::withoutGlobalScopes()->where('room_id', $room->getKey())->count())->toBe(0);
    }
});

it('loads the room data once for the range, not once per day', function () {
    [$tenant, $service, $staff, $rooms] = roomAssignWorld(rooms: 3, staff: 2);

    foreach ($staff as $member) {
        roomAssignHours($tenant, $member);
    }
    foreach ($rooms as $room) {
        roomAssignHours($tenant, $room);
    }

    $from = roomAssignDay();
    $to = $from->copy()->addDays(20);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(AvailabilityService::class)->slotsForRange($service, $from, $to);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    // ⚠️ A three-week calendar must cost the same number of queries as one day.
    // The room lookups are bulk `whereIn` loads over the whole span, exactly like
    // the staff ones (SLO-83) — a per-day load would be 21× this.
    $loads = fn (string $table): int => $queries
        ->filter(fn (string $sql): bool => str_contains($sql, "from \"{$table}\""))
        ->count();

    expect($loads('schedules'))->toBe(2)
        ->and($loads('schedule_exceptions'))->toBe(2)
        ->and($loads('bookings'))->toBe(2);
});
