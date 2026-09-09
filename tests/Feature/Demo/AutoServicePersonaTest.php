<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\PlanLimitKey;
use App\Enums\QuoteRequestStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Location;
use App\Models\QuoteRequest;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\AvailabilityService;
use App\Services\Feature\FeatureResolver;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\AutoServiceDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| „Csavarkulcs Autószerviz" (SLO-197, docs/22 §2)
|--------------------------------------------------------------------------
|
| The first non-wellness vertical, and the persona that found SLO-200. What is
| pinned here is what the other four cannot show:
|
|   the lift is the scarce resource and nobody picks it — the engine does;
|   a Saturday where the staff rota and the bay rota have to agree before a
|   slot exists; an eight-hour drop-off; and approval, quote and plain booking
|   inside one tenant.
|
| Grouped into few tests for the reason the other personas give: RefreshDatabase
| makes every test pay for the seed, and this one is ~90 seconds of it.
|
*/

beforeEach(function () {
    // ⚠️ One frame for the seed AND the assertions (SLO-211).
    //
    // DemoDataFactory pins its own `today` at construction, precisely so a seed
    // cannot straddle midnight. The tests then read `Carbon::today()` again —
    // about ninety seconds later, because that is what the seed costs. Those two
    // clocks are allowed to disagree, and when they do the test is asking about
    // a different day than the one it built.
    //
    // Frozen rather than tolerated: every date below is relative to this anchor,
    // so nothing here is pinned to a real calendar date, and a suite that starts
    // at 23:59 asks the same question as one that starts at noon.
    Carbon::setTestNow(Carbon::parse('2026-09-09 09:00:00', 'Europe/Budapest'));

    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
    Carbon::setTestNow();
});

function garage(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new AutoServiceDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()->where('slug', (new AutoServiceDemoPersona)->slug())->sole();
}

/** @return Builder<Booking> */
function garageBookings(Tenant $tenant): Builder
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
}

function garageService(Tenant $tenant, string $name): Service
{
    return Service::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('name', 'like', $name.'%')
        ->sole();
}

/** Slot start times (tenant-local H:i) offered for a service on a date. */
function garageSlots(Tenant $tenant, Service $service, Carbon $day): array
{
    return collect(app(AvailabilityService::class)->slotsForDay($service, $day))
        ->map(fn ($slot): string => $slot->start->copy()->timezone($tenant->timezone)->format('H:i'))
        ->all();
}

it('builds the workshop the data sheet describes', function () {
    $tenant = garage();
    $id = $tenant->getKey();

    expect($tenant->is_demo)->toBeTrue()
        ->and(Location::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(1)
        ->and(Room::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(3)
        ->and(Staff::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(3);

    // Branding: the workshop must NOT look like the salon. The flag matters as
    // much as the colour — it is off by default on the base plan, so writing the
    // JSON alone would leave the customisation invisible.
    expect($tenant->branding['primary_color'] ?? null)->toBe('#2f3640')
        ->and(app(FeatureResolver::class)
            ->enabled($tenant, Feature::Branding))->toBeTrue();

    // Three booking modes' worth of catalogue, and the two that need approval.
    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get();

    expect($services)->toHaveCount(9)
        ->and($services->where('booking_mode', BookingMode::QuoteRequest)->count())->toBe(1)
        ->and($services->where('requires_approval', true)->count())->toBe(2);

    // ⚠️ Both pivots on every timed service. Without service_rooms there is no
    // bay to assign and `requires_room` refuses every slot; without service_staff
    // the public page offers nothing at all.
    $timed = $services->where('booking_mode', BookingMode::DurationBased);

    foreach ($timed as $service) {
        expect($service->requires_room)->toBeTrue("{$service->name} does not need a bay")
            ->and($service->requires_staff)->toBeTrue("{$service->name} does not need a mechanic")
            ->and($service->rooms()->count())->toBeGreaterThan(0, "{$service->name} has no bay")
            ->and($service->staff()->count())->toBeGreaterThan(0, "{$service->name} has no mechanic");

        // The price caveat, on every one: a labour price a customer reads as
        // "all in" is how a workshop loses somebody at the counter.
        expect($service->description)->toContain('alkatrész nélkül');

        // The registration plate has to be asked for while it still lives in the
        // notes box (SLO-197; structured fields are SLO-199).
        expect($service->notesHint())->toContain('rendszám');
    }

    // The owner is one person, not two: his staff row and his login are the same
    // human, or the calendar he appears in is not the account he signs into.
    $owner = Staff::withoutGlobalScopes()->where('tenant_id', $id)->whereNotNull('user_id')->sole();
    expect($owner->name)->toBe('Kovács Gábor');

    // A Manager behind the counter — the only way the permission matrix is
    // demonstrable (docs/03): what she cannot reach is the demo.
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);
    $adviser = User::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Szabó Kinga')->sole();
    expect($adviser->hasRole(Role::Manager->value))->toBeTrue();

    // Comfortably inside the base plan (8/3/8 since SLO-195). Asked with one
    // fewer than seeded, the way the other personas do: the question is whether
    // the workshop could still have added the last mechanic it has.
    $limits = app(PlanLimitService::class);

    expect($limits->withinLimit(PlanLimitKey::MaxEmployees, Staff::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxLocations, Location::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxRooms, Room::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue();
});

it('⚠️ opens the tyre bay on Saturday and nothing else', function () {
    $tenant = garage();
    $timezone = $tenant->timezone;

    $saturday = Carbon::today($timezone)->next(Carbon::SATURDAY);
    $wednesday = Carbon::today($timezone)->next(Carbon::WEDNESDAY);

    $wheels = garageService($tenant, 'Kerékcsere');
    $oil = garageService($tenant, 'Olajcsere');

    // ⚠️ THE persona. On a Saturday only the tyre fitter works and only the tyre
    // bay is open, so the two rotas have to agree before a slot exists. A wheel
    // change is bookable; an oil change — whose mechanics are off AND whose bays
    // are shut — is not. Both halves matter: filtering on staff alone would
    // still offer a slot no bay could host, which is the bug SLO-200 fixed.
    expect(garageSlots($tenant, $wheels, $saturday))
        ->not->toBeEmpty('No wheel change on a Saturday — the tyre bay is meant to be open');

    expect(garageSlots($tenant, $oil, $saturday))
        ->toBeEmpty('An oil change was offered on a Saturday, when neither its mechanics nor its bays are available');

    // ...and midweek both are bookable, so the Saturday result above is a rota
    // talking, not a service that is broken.
    //
    // ⚠️ Asked of the WEEK, not of one chosen day (SLO-211). A single weekday can
    // legitimately sell out here: `seedExceptions` puts Attila on leave on days
    // +6 and +7, an eight-hour MOT holds a bay for a whole day, and Gábor's two
    // or three jobs take the other one. That is realistic workshop data, not a
    // defect — but `today->next(WEDNESDAY)` lands on +6 or +7 whenever the suite
    // runs on a Wednesday or a Thursday, so the old single-day assertion failed
    // about once in every two full runs and passed on the retry.
    //
    // What the assertion is actually for is unchanged: proving the empty
    // Saturday above is the rota speaking rather than a broken service. One
    // bookable weekday proves exactly that, and a service that stopped working
    // would still leave none.
    $midweek = collect(range(1, 14))
        ->map(fn (int $days): Carbon => Carbon::today($timezone)->addDays($days))
        ->reject(fn (Carbon $day): bool => $day->isWeekend());

    // ⚠️ The counts go into the failure message on purpose. The original flake
    // reported only "Expecting [] not to be empty", which said nothing about
    // WHICH day was sold out or whether the neighbours were — and that is the
    // evidence the investigation needed and did not have. If this ever goes red
    // again, the CI output names every day and its capacity.
    $census = fn (Service $service): string => $midweek
        ->map(fn (Carbon $day): string => $day->format('D d.').' '.count(garageSlots($tenant, $service, $day)))
        ->implode(' | ');

    $bookableOn = fn (Service $service): bool => $midweek
        ->contains(fn (Carbon $day): bool => garageSlots($tenant, $service, $day) !== []);

    expect($bookableOn($wheels))
        ->toBeTrue('No weekday in the next fortnight offers a wheel change: '.$census($wheels))
        ->and($bookableOn($oil))
        ->toBeTrue('No weekday in the next fortnight offers an oil change: '.$census($oil));

    // The all-day drop-off takes a bay for the whole working day, which is why
    // it is the only service in the demo set that cannot be offered twice.
    $mot = garageService($tenant, 'Műszaki vizsga');
    expect((int) $mot->duration_minutes)->toBe(480);

    $motSlots = garageSlots($tenant, $mot, $wednesday);
    expect(count($motSlots))->toBeLessThanOrEqual(2, 'An eight-hour job cannot start more than twice in a nine-hour day');
});

it('fills six months of trade without ever double-booking a bay', function () {
    $tenant = garage();

    $bookings = garageBookings($tenant)->whereNotNull('starts_at')->orderBy('starts_at')->get();

    expect($bookings->count())->toBeGreaterThan(600);

    // ⚠️ Every timed booking holds a real bay. Before SLO-200 these came out with
    // `room_id = null` — and a null bay is a bay nothing defends, so the overlap
    // check below would have passed while two cars sat in one lift.
    expect($bookings->every(fn (Booking $b): bool => $b->room_id !== null))
        ->toBeTrue('A booking was written without a bay');

    // No two occupying bookings overlap on the same bay or the same mechanic.
    $occupying = $bookings->filter(fn (Booking $b): bool => in_array($b->status->value, BookingStatus::occupyingValues(), true));

    foreach (['room_id', 'staff_id'] as $resource) {
        foreach ($occupying->groupBy($resource) as $resourceId => $group) {
            $ordered = $group->sortBy('starts_at')->values();

            for ($i = 1; $i < $ordered->count(); $i++) {
                expect($ordered[$i]->starts_at->gte($ordered[$i - 1]->ends_at))->toBeTrue(sprintf(
                    'Two bookings overlap on %s %s: %s and %s',
                    $resource, $resourceId,
                    $ordered[$i - 1]->starts_at->toDateTimeString(),
                    $ordered[$i]->starts_at->toDateTimeString(),
                ));
            }
        }
    }

    // Every bay earns its keep — a third bay nothing is ever booked into would
    // be a data sheet that does not match the calendar.
    foreach (Room::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get() as $bay) {
        expect($occupying->where('room_id', $bay->getKey())->count())
            ->toBeGreaterThan(0, "Nothing was ever booked into {$bay->name}");
    }

    // The seasonality the statistics module exists to show: the tyre peak is
    // anchored to the seed date, so it lands in the same place on the curve
    // whatever month the demo is opened in.
    $wheels = garageService($tenant, 'Kerékcsere');
    $inSeason = $bookings->filter(fn (Booking $b): bool => $b->service_id === $wheels->getKey()
        && $b->starts_at->between(Carbon::now()->subDays(150), Carbon::now()->subDays(120)))->count();
    $outOfSeason = $bookings->filter(fn (Booking $b): bool => $b->service_id === $wheels->getKey()
        && $b->starts_at->between(Carbon::now()->subDays(90), Carbon::now()->subDays(60)))->count();

    expect($inSeason)->toBeGreaterThan($outOfSeason * 2, 'The tyre season is not visible on the curve');

    // The plate is in the notes, in one shape, on every booking that has one —
    // which is what makes the stop-gap usable until SLO-199 lands.
    $withNotes = $bookings->filter(fn (Booking $b): bool => $b->notes !== null);
    expect($withNotes)->not->toBeEmpty()
        ->and($withNotes->every(fn (Booking $b): bool => str_starts_with((string) $b->notes, 'Rendszám: ')))
        ->toBeTrue('A booking note does not carry the plate in the agreed shape');
});

it('shows every decision a service adviser has to make', function () {
    $tenant = garage();

    // Approval, quote and plain booking in ONE tenant — the venue is quote-led,
    // the practice approval-led; this is the only persona with all three.
    foreach (QuoteRequestStatus::cases() as $status) {
        expect(QuoteRequest::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', $status->value)->count())
            ->toBeGreaterThan(0, "No enquiry in status {$status->value}");
    }

    // Exactly one pending request: the MOT waiting on a decision. More than one
    // would mean the historical approval-required jobs were never answered,
    // which is a workshop that does not read its email.
    $pending = garageBookings($tenant)->where('status', BookingStatus::Requested)->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->hold_expires_at->isFuture())->toBeTrue()
        ->and($pending->first()->starts_at->isFuture())->toBeTrue();

    // ...and a refusal with a reason somebody can act on — the half of the
    // approval flow demos usually forget.
    $rejected = garageBookings($tenant)->where('status', BookingStatus::Rejected)->get();
    expect($rejected)->not->toBeEmpty()
        ->and($rejected->first()->reject_reason)->toContain('vizsgaidőpont');

    foreach ([BookingStatus::NoShow, BookingStatus::Canceled, BookingStatus::Completed] as $status) {
        expect(garageBookings($tenant)->where('status', $status->value)->count())
            ->toBeGreaterThan(0, "No booking in status {$status->value}");
    }

    // A conversation inside an enquiry (docs/22 §2) — the messaging surface the
    // salon deliberately does not have.
    $talking = QuoteRequest::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->has('messages', '>=', 2)
        ->count();

    expect($talking)->toBeGreaterThan(0, 'No enquiry carries a conversation');
});

it('serves a public page a visitor can actually book on', function () {
    $tenant = garage();
    $slug = $tenant->slug;

    $this->get(tenantHost($slug, '/'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('profile.name', 'Csavarkulcs Autószerviz'));

    $oil = garageService($tenant, 'Olajcsere');

    // ⚠️ Deliberately NOT the dateless `/book`: the picker lands on today, and
    // this workshop is shut on Sundays. Asking about the landing day is an
    // assertion that holds six days a week — the exact bug that sat on `main`
    // in two other personas until a Sunday CI run found it.
    $day = Carbon::today($tenant->timezone)->addDay();
    $offered = 0;

    for ($i = 0; $i < 7; $i++, $day->addDay()) {
        $slots = $this->get(tenantHost($slug, '/book?service='.$oil->getKey().'&date='.$day->toDateString()))
            ->assertOk()
            ->viewData('page')['props']['slots'] ?? [];

        // An oil change needs a workshop mechanic and a workshop bay: weekdays
        // only. The weekend must be empty in BOTH directions.
        if ($day->isWeekend()) {
            expect($slots)->toBeEmpty("An oil change was offered on {$day->toDateString()}, a weekend");

            continue;
        }

        $offered += $slots === [] ? 0 : 1;
    }

    expect($offered)->toBeGreaterThan(0, 'An oil change is bookable on no weekday of the coming week');

    // The notes hint reaches the page, from the service rather than the code.
    $response = $this->get(tenantHost($slug, '/book?service='.$oil->getKey()));
    expect($response->viewData('page')['props']['service']['notes_hint'])->toContain('rendszám');

    // The enquiry form renders its questions — the mode's whole surface.
    $enquiry = garageService($tenant, 'Ajánlatkérés');
    $this->get(tenantHost($slug, '/book?service='.$enquiry->getKey()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('service.booking_mode', BookingMode::QuoteRequest->value)
            ->has('quote_fields', 6));
});

it('builds on whatever weekday the nightly reset happens to run', function (string $today) {
    // ⚠️ Every date here is an offset from "today", and the reset runs at 03:00
    // (SLO-191) — the hour at which an instant seeded for "today, 08:00" is still
    // in the FUTURE. Two other personas shipped bugs from exactly this, so the
    // whole week is walked rather than sampled.
    Carbon::setTestNow(Carbon::parse($today.' 03:00', 'Europe/Budapest'));

    $tenant = garage();

    // The cause, not the symptom: nothing may be dated after the moment the
    // seed ran. A future-dated booking is one the demo does not yet have.
    expect(garageBookings($tenant)->where('created_at', '>', Carbon::now())->count())
        ->toBe(0, "A booking was created in the future when seeded on {$today}");

    // Nothing in the past is left hanging: a months-old `confirmed` job both
    // looks abandoned and counts revenue that may never have happened.
    expect(garageBookings($tenant)
        ->whereNotNull('ends_at')
        ->where('ends_at', '<', Carbon::now())
        ->whereIn('status', [BookingStatus::Requested->value, BookingStatus::Confirmed->value])
        ->count())
        ->toBe(0, "A past booking was left non-terminal when seeded on {$today}");

    // The one pending decision is still pending, and its soft hold still live —
    // seeded carelessly it would be one sweep away from cancelling itself.
    $pending = garageBookings($tenant)->where('status', BookingStatus::Requested)->sole();

    expect($pending->hold_expires_at->isFuture())->toBeTrue("The MOT hold had already lapsed on {$today}")
        ->and($pending->starts_at->isFuture())->toBeTrue();

    // And the Saturday rota still holds, whatever day the week started on.
    $saturday = Carbon::today($tenant->timezone)->next(Carbon::SATURDAY);
    expect(garageSlots($tenant, garageService($tenant, 'Kerékcsere'), $saturday))->not->toBeEmpty()
        ->and(garageSlots($tenant, garageService($tenant, 'Olajcsere'), $saturday))->toBeEmpty();
})->with([
    '2026-09-07', // Monday
    '2026-09-09', // Wednesday
    '2026-09-11', // Friday
    '2026-09-12', // Saturday — the reset runs then too
    '2026-09-13', // Sunday
]);

it('keeps the bays and the rota consistent with each other', function () {
    $tenant = garage();
    $id = $tenant->getKey();

    $tyreBay = Room::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Gumis állás')->sole();

    $saturdayBands = Schedule::withoutGlobalScopes()
        ->where('tenant_id', $id)
        ->where('day_of_week', 6)
        ->get();

    // Exactly two Saturday bands: the tyre fitter and his bay. A third would put
    // somebody in the building with nothing to work in, or a bay open with
    // nobody in it — and either makes the Saturday demo a lie.
    expect($saturdayBands)->toHaveCount(2)
        ->and($saturdayBands->pluck('schedulable_id')->contains($tyreBay->getKey()))->toBeTrue();

    // The rota exception is visible in both directions: a day off ahead, and a
    // closed day behind.
    $exceptions = ScheduleException::withoutGlobalScopes()->where('tenant_id', $id)->get();

    expect($exceptions->filter(fn ($e): bool => Carbon::parse($e->date)->isFuture()))->not->toBeEmpty()
        ->and($exceptions->filter(fn ($e): bool => Carbon::parse($e->date)->isPast()))->not->toBeEmpty();
});
