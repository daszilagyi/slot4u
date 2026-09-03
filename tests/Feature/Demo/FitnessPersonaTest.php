<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\PlanLimitKey;
use App\Enums\Role;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Event;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Booking\AvailabilityService;
use App\Services\Feature\FeatureResolver;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\FitnessDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| „Premium Fitness Studio" 1/3 (SLO-188, docs/20 §2.3)
|--------------------------------------------------------------------------
|
| The flagship's foundation: two sites, a team of six on three roles, and the
| services that need neither events nor money. The timetable (SLO-189) and the
| payment history (SLO-190) extend the same persona class, so what is pinned
| here is what those must not break.
|
| The load-bearing assertion is the multi-location one. A trainer who works at
| both sites is the only reason the location filter on the public calendar
| means anything, and the rule is subtle enough to regress silently: a band is
| offered when the visitor asked for its site OR asked for none — never when
| they asked for the other one.
|
| Three tests, each asserting a lot — and STILL three after SLO-189 added the
| timetable. The seed is ~2,300 bookings and RefreshDatabase pays for it per
| test, so a fourth would cost a minute of CI for assertions that fit perfectly
| well inside the existing three: what exists, where it is, and whether it hangs
| together.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
    Carbon::setTestNow();
});

function fitness(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new FitnessDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()->where('slug', (new FitnessDemoPersona)->slug())->sole();
}

/**
 * @return Builder<Booking>
 */
function fitnessBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
}

// --- The studio ------------------------------------------------------------

it('builds two studios, a team of six and three roles behind the desk', function () {
    $tenant = fitness();
    $id = $tenant->getKey();

    $locations = Location::withoutGlobalScopes()->where('tenant_id', $id)->orderBy('sort_order')->get();

    expect($locations->pluck('name')->all())->toBe(['Premium Fitness Buda', 'Premium Fitness Pest'])
        ->and(Room::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(4)
        ->and(Staff::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(6);

    // Three people behind the desk on three DIFFERENT roles — the only way the
    // permission matrix is demonstrable at all (docs/20 §2.3).
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);
    $slug = $tenant->slug;

    $owner = User::withoutGlobalScopes()->where('email', 'admin@'.$slug.'.demo.slot4u.hu')->sole();
    $manager = User::withoutGlobalScopes()->where('email', 'manager@'.$slug.'.demo.slot4u.hu')->sole();
    $employee = User::withoutGlobalScopes()->where('email', 'recepcio@'.$slug.'.demo.slot4u.hu')->sole();

    expect($owner->hasRole(Role::TenantAdmin->value))->toBeTrue()
        ->and($manager->hasRole(Role::Manager->value))->toBeTrue()
        ->and($employee->hasRole(Role::Employee->value))->toBeTrue();

    // And the roles actually differ where it shows: only the owner reaches the
    // settings, and only the owner and the manager reach the reports.
    $this->actingAs($owner)->get(tenantHost($slug, '/settings'))->assertOk();
    $this->actingAs($manager)->get(tenantHost($slug, '/settings'))->assertForbidden();
    $this->actingAs($manager)->get(tenantHost($slug, '/reports'))->assertOk();
    $this->actingAs($employee)->get(tenantHost($slug, '/reports'))->assertForbidden();

    // --- features ----------------------------------------------------------
    $resolver = app(FeatureResolver::class);

    // Off by default on the base plan, switched on for this tenant — the Max
    // package (docs/20 §2.3) expressed the way the product actually expresses
    // it, through tenant_features.
    foreach ([Feature::Branding, Feature::OnlinePayment, Feature::Invoicing, Feature::CustomDomain] as $feature) {
        expect($resolver->enabled($tenant, $feature))->toBeTrue("{$feature->value} should be on");
    }

    // ⚠️ NOT everything, despite the spec's "minden feature bekapcsolva". These
    // have no implementation behind them at all, so switching them on would put
    // doors in the sales demo that open onto nothing.
    //
    // `feature_documents` is absent from this list on purpose: it is equally
    // unimplemented, but it is ON by default for EVERY tenant on the base plan
    // (Feature::enabledByDefaultOnBase falls through to true), so it is not
    // this persona's to leave off. Filed separately rather than worked around
    // here.
    foreach ([Feature::Sms, Feature::Api, Feature::NlpBooking, Feature::GoogleMeet] as $feature) {
        expect($resolver->enabled($tenant, $feature))->toBeFalse("{$feature->value} is not built and must stay off");
    }

    // --- the catalogue -----------------------------------------------------
    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get()->keyBy('name');

    // Three from SLO-188 (training, sauna, PT box) plus the six kinds of class
    // the timetable needs (SLO-189).
    expect($services)->toHaveCount(9);

    $personal = $services['Személyi edzés'];

    expect($personal->price_minor)->toBe(1_400_000)
        ->and($personal->staff()->count())->toBe(3)
        // No room, deliberately: pinning personal training to a hall would put
        // it in the way of SLO-189's classes, and an event sign-up claims
        // capacity rather than locking the room, so the engine would not catch
        // the clash.
        ->and($personal->rooms()->count())->toBe(0);

    // ⚠️ The free-range rental (docs/04 §4) — the only one in the demo set.
    $box = $services['PT-box bérlés (alkalmi díj)'];

    expect($box->booking_mode)->toBe(BookingMode::ResourceRental)
        ->and($box->duration_minutes)->toBeNull()
        ->and($box->rentalDurationBounds())->toBe(['min' => 60, 'max' => 180])
        // Priced as a block, not by the hour: price_minor is a flat per-booking
        // snapshot, so an hourly rate would charge the same for one hour as for
        // three and read as a bug (per-hour pricing needs SLO-193).
        ->and($box->price_minor)->toBe(1_800_000);

    expect($services['Szaunabérlés']->duration_minutes)->toBe(60);

    // --- the weekly timetable (SLO-189) ------------------------------------
    // An Event has no name of its own, so each kind of class has to BE a
    // service — which is also how the public page lists them.
    $classes = Service::withoutGlobalScopes()
        ->where('tenant_id', $id)
        ->where('booking_mode', BookingMode::EventBased)
        ->get();

    expect($classes)->toHaveCount(6)
        ->and($classes->pluck('name')->all())->toContain('Spinning', 'Hatha jóga', 'Funkcionális edzés')
        ->and($classes->every(fn (Service $c): bool => $c->waitlist_enabled))->toBeTrue()
        ->and($classes->pluck('price_minor')->unique()->all())->toBe([390_000]);

    $events = Event::withoutGlobalScopes()->where('tenant_id', $id)->get();

    // Fifteen classes a week (docs/20 §2.3), published as real weekly series
    // rather than a few hundred unrelated rows that merely look like one.
    expect($events->pluck('series_id')->filter()->unique())->toHaveCount(15)
        ->and($events->count())->toBeGreaterThan(150)
        ->and($events->every(fn (Event $e): bool => $e->capacity >= 10 && $e->capacity <= 16))->toBeTrue()
        // Both rooms are in use, and the timetable runs behind AND ahead.
        ->and($events->pluck('room_id')->unique())->toHaveCount(2)
        ->and($events->filter(fn (Event $e): bool => $e->starts_at->isPast()))->not->toBeEmpty()
        ->and($events->filter(fn (Event $e): bool => $e->starts_at->isFuture()))->not->toBeEmpty();

    // ⚠️ Every class sits inside its instructor's own shift. Nothing enforces
    // this — an event is announced, not generated from availability — so a
    // timetable with the yoga teacher on the floor at an hour her diary says
    // she is off is a mistake only a test catches.
    $bands = Schedule::withoutGlobalScopes()
        ->where('tenant_id', $id)
        ->where('schedulable_type', 'staff')
        ->get()
        ->groupBy('schedulable_id');

    foreach ($events as $event) {
        $local = $event->starts_at->copy()->timezone($tenant->timezone);
        $endsLocal = $event->ends_at->copy()->timezone($tenant->timezone);

        $covered = ($bands[$event->staff_id] ?? collect())
            ->where('day_of_week', $local->dayOfWeekIso)
            ->contains(fn (Schedule $band): bool => substr((string) $band->start_time, 0, 5) <= $local->format('H:i')
                && substr((string) $band->end_time, 0, 5) >= $endsLocal->format('H:i'));

        expect($covered)->toBeTrue(
            "Class at {$local->format('D H:i')} falls outside its instructor's shift"
        );
    }
});

// --- Multi-location --------------------------------------------------------

it('⚠️ keeps a two-site trainer in one place at a time', function () {
    $tenant = fitness();
    $id = $tenant->getKey();

    $buda = Location::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Premium Fitness Buda')->sole();
    $pest = Location::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Premium Fitness Pest')->sole();
    $adam = Staff::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Barta Ádám')->sole();

    // He is on the roster at both sites (SLO-51: staff_locations says WHERE)...
    expect($adam->locations()->count())->toBe(2);

    $bands = Schedule::withoutGlobalScopes()
        ->where('schedulable_type', 'staff')
        ->where('schedulable_id', $adam->getKey())
        ->get();

    // ...and his hours say WHEN, differently per site.
    expect($bands->pluck('location_id')->unique()->count())->toBe(2);

    // No weekday carries bands at both sites: he cannot be in two places at
    // once, and a demo that implied otherwise would be selling a lie.
    $byDay = $bands->groupBy('day_of_week');
    foreach ($byDay as $day => $dayBands) {
        expect($dayBands->pluck('location_id')->unique()->count())->toBe(
            1, "Barta Ádám is rostered at two sites on weekday {$day}"
        );
    }

    // The rule that makes the public location filter mean something: a band is
    // offered when the visitor asked for its site, or asked for none — never
    // when they asked for the other one.
    $personal = Service::withoutGlobalScopes()->where('tenant_id', $id)->where('name', 'Személyi edzés')->sole();
    $availability = app(AvailabilityService::class);

    $budaDay = $bands->firstWhere('location_id', $buda->getKey());
    $date = Carbon::today($tenant->timezone)->next((int) $budaDay->day_of_week)->addWeek();

    $atBuda = $availability->slotsForDay($personal, $date, $adam->getKey(), null, $buda->getKey());
    $atPest = $availability->slotsForDay($personal, $date, $adam->getKey(), null, $pest->getKey());
    $anywhere = $availability->slotsForDay($personal, $date, $adam->getKey());

    expect($atBuda)->not->toBeEmpty()
        // Same trainer, same day, other site: nothing.
        ->and($atPest)->toBeEmpty()
        // And with no site asked for, every band counts.
        ->and(count($anywhere))->toBe(count($atBuda));

    // The public page carries the filter through.
    $this->get(tenantHost($tenant->slug, '/book?service='.$personal->getKey().'&location='.$buda->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.location', $buda->getKey()));
});

// --- Bookable, and internally consistent -----------------------------------

it('offers both rentals publicly and never double-books a resource', function () {
    $tenant = fitness();
    $id = $tenant->getKey();
    $slug = $tenant->slug;

    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get()->keyBy('name');

    // Both rentals are bookable from the public page (SLO-188 AC).
    foreach (['Szaunabérlés', 'PT-box bérlés (alkalmi díj)'] as $name) {
        $response = $this->get(tenantHost($slug, '/book?service='.$services[$name]->getKey()));
        $response->assertOk();

        expect($response->viewData('page')['props']['slots'] ?? [])->not->toBeEmpty("{$name} offers no slots");
    }

    // The box advertises its duration picker; the sauna does not have one.
    $this->get(tenantHost($slug, '/book?service='.$services['PT-box bérlés (alkalmi díj)']->getKey()))
        ->assertInertia(fn (Assert $page) => $page
            ->where('service.min_duration_minutes', 60)
            ->where('service.max_duration_minutes', 180));

    $this->get(tenantHost($slug, '/book?service='.$services['Szaunabérlés']->getKey()))
        ->assertInertia(fn (Assert $page) => $page->where('service.min_duration_minutes', null));

    // Customers picked genuinely different lengths, which is the whole point of
    // a free-range rental — all one length would demo nothing.
    $lengths = fitnessBookings($tenant)
        ->where('service_id', $services['PT-box bérlés (alkalmi díj)']->getKey())
        ->get()
        ->map(fn (Booking $b): int => (int) $b->starts_at->diffInMinutes($b->ends_at))
        ->unique();

    expect($lengths->count())->toBeGreaterThan(2)
        ->and($lengths->min())->toBeGreaterThanOrEqual(60)
        ->and($lengths->max())->toBeLessThanOrEqual(180);

    // --- invariants --------------------------------------------------------
    $occupying = fitnessBookings($tenant)
        ->whereNotNull('starts_at')
        ->whereIn('status', BookingStatus::occupyingValues())
        ->orderBy('starts_at')
        ->get();

    expect($occupying)->not->toBeEmpty();

    // Nobody and nothing is in two places at once. Grouped per resource because
    // personal training books a trainer with no room and the rentals book a
    // room with no trainer — they can and should run at the same time.
    foreach (['staff_id', 'room_id'] as $column) {
        foreach ($occupying->whereNotNull($column)->groupBy($column) as $resourceId => $forResource) {
            $previous = null;
            foreach ($forResource->sortBy('starts_at') as $booking) {
                if ($previous !== null) {
                    expect($previous->ends_at->lte($booking->starts_at))->toBeTrue(
                        "{$column} {$resourceId} double-booked: {$previous->starts_at} → {$previous->ends_at} vs {$booking->starts_at}"
                    );
                }
                $previous = $booking;
            }
        }
    }

    // Half a year behind, a fortnight ahead, and nothing left hanging in the past.
    $past = $occupying->filter(fn (Booking $b): bool => $b->starts_at->isPast());

    expect(fitnessBookings($tenant)->count())->toBeGreaterThan(500)
        ->and($past->first()->starts_at->diffInDays(now()))->toBeGreaterThan(150)
        ->and(fitnessBookings($tenant)->where('status', BookingStatus::Completed->value)->count())->toBeGreaterThan(300);

    // Nothing that has ENDED is still merely confirmed. Deliberately `ends_at`
    // rather than `starts_at`: a session that started an hour ago and runs for
    // another fifteen minutes is correctly still confirmed, and asserting on
    // the start would fail on whichever hour the suite happened to run.
    expect(fitnessBookings($tenant)
        ->whereNotNull('ends_at')
        ->where('ends_at', '<', now())
        ->where('status', BookingStatus::Confirmed->value)
        ->count())->toBe(0);

    // Plan-consistent (docs/20 §1.6): a visitor has to be able to rebuild this
    // in their own account — true only under the raised ceilings of SLO-195.
    $limits = app(PlanLimitService::class);

    expect($limits->withinLimit(PlanLimitKey::MaxLocations, Location::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxRooms, Room::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxEmployees, Staff::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue();

    // --- classes, capacity and the queue behind them (SLO-189) -------------
    $events = Event::withoutGlobalScopes()->where('tenant_id', $id)->get();

    // ⚠️ The invariant the atomic capacity claim exists to hold (docs/04 §3).
    // Nothing else in the seed could catch an off-by-one here.
    expect($events->every(fn (Event $e): bool => $e->booked_count <= $e->capacity))->toBeTrue()
        // …and it is a real count, not a number nobody wrote to.
        ->and($events->sum('booked_count'))->toBeGreaterThan(0);

    foreach ($events as $event) {
        $seats = fitnessBookings($tenant)
            ->where('event_id', $event->getKey())
            ->whereIn('status', BookingStatus::occupyingValues())
            ->sum('party_size');

        expect((int) $seats)->toBe($event->booked_count, "Event {$event->getKey()} booked_count disagrees with its sign-ups");
    }

    // Somebody brought a friend: the claim takes two seats in one go.
    expect(fitnessBookings($tenant)->where('party_size', '>', 1)->count())->toBeGreaterThan(0);

    // Every waitlist state on screen at once (docs/20 §2.3) — the persona's
    // best moment in a sales call, and four states that only appear if the
    // whole flow was actually driven.
    $entries = WaitlistEntry::withoutGlobalScopes()->where('tenant_id', $id)->get();

    foreach (WaitlistStatus::cases() as $status) {
        expect($entries->where('status', $status)->count())
            ->toBeGreaterThan(0, "No waitlist entry left in state {$status->value}");
    }

    // ⚠️ One offer must still be OPEN. An offer only stands for the tenant's
    // window (24h), so `offered_until` in the past is not something anyone can
    // accept — the expiry sweep would clear it and the demo would lose the
    // state overnight (SLO-25).
    expect($entries->where('status', WaitlistStatus::Offered)
        ->contains(fn (WaitlistEntry $e): bool => $e->offered_until !== null && $e->offered_until->isFuture()))
        ->toBeTrue('No live waitlist offer — the demo would show only lapsed ones');

    // Queue positions are FIFO and distinct per event.
    foreach ($entries->groupBy('event_id') as $eventId => $queue) {
        $positions = $queue->pluck('position');
        expect($positions->unique()->count())->toBe($positions->count(), "Duplicate queue position on event {$eventId}");
    }

    // --- the public page offers the queue on a full class ------------------
    $full = $events->first(fn (Event $e): bool => $e->starts_at->isFuture() && $e->booked_count >= $e->capacity);

    expect($full)->not->toBeNull('No sold-out class ahead — nothing to demo the waitlist on');

    $response = $this->get(tenantHost($slug, '/book?service='.$full->service_id));
    $response->assertOk();

    $offered = collect($response->viewData('page')['props']['events'] ?? [])
        ->firstWhere('id', $full->getKey());

    expect($offered)->not->toBeNull()
        ->and($offered['is_full'])->toBeTrue()
        ->and($offered['remaining'])->toBe(0)
        // The whole point: a full class is not a dead end.
        ->and($offered['waitlist_available'])->toBeTrue();

    // And the admin can browse the timetable filtered by room and instructor
    // (docs/20 §2.3 AC).
    $owner = User::withoutGlobalScopes()->where('email', 'admin@'.$slug.'.demo.slot4u.hu')->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);

    $this->actingAs($owner)
        ->get(tenantHost($slug, '/calendar?view=week&room_id='.$full->room_id.'&staff_id='.$full->staff_id))
        ->assertOk();
});
