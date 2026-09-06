<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\NotificationType;
use App\Enums\PlanLimitKey;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Location;
use App\Models\MessageTemplate;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\SalonDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| „GlamZone Szépségszalon" (SLO-185, docs/20 §2.2)
|--------------------------------------------------------------------------
|
| The multi-staff SME persona: what only becomes demonstrable once there is
| more than one person behind the counter — staff choice and "anyone", shifts
| that differ per person, half a year of trade for the statistics module, the
| branding switch, and the Manager role.
|
| ⚠️ Deliberately only three tests, each asserting a lot.
|
| Seeding this persona costs ~30 seconds: half a year at the 6–10 appointments
| a day the spec asks for is ~1200 bookings, and every one of them goes through
| the real Actions (docs/20 §3.3) because that is what makes the history
| coherent. RefreshDatabase rebuilds per test, so each test pays that in full —
| splitting these assertions six ways would buy nothing but three more minutes
| of CI. They are grouped by subsystem instead: structure, bookability,
| numbers.
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

function glamzone(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new SalonDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()->where('slug', (new SalonDemoPersona)->slug())->sole();
}

/**
 * @return Builder<Booking>
 */
function glamzoneBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
}

// --- The salon itself ------------------------------------------------------

it('builds the salon the data sheet describes, branded and staffed', function () {
    $tenant = glamzone();
    $id = $tenant->getKey();

    // --- structure ---------------------------------------------------------
    expect($tenant->is_demo)->toBeTrue()
        ->and(Location::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(1);

    $staff = Staff::withoutGlobalScopes()->where('tenant_id', $id)->orderBy('id')->get();

    expect($staff->pluck('name')->all())->toBe([
        'Kovács Réka', 'Tóth Bence', 'Szabó Nóra', 'Kiss Dorina',
    ]);

    // ⚠️ Four rooms, not the three docs/20 §2.2 names. A room is an EXCLUSIVE
    // resource in the conflict check, so two stylists sharing one floor would
    // make every second appointment in the salon a double-booking. Two chairs
    // are two rooms; the three functional areas survive in the naming.
    $rooms = Room::withoutGlobalScopes()->where('tenant_id', $id)->orderBy('id')->get();

    expect($rooms)->toHaveCount(4)
        ->and($rooms->pluck('name')->all())->toContain('Fodrász tér — 1. szék', 'Fodrász tér — 2. szék');

    // --- shifts that actually differ ---------------------------------------
    $shifts = Schedule::withoutGlobalScopes()
        ->where('tenant_id', $id)
        ->where('schedulable_type', 'staff')
        ->get()
        ->groupBy('schedulable_id');

    // The point of the whole persona: were these identical, the calendar's
    // staff filter would be a control with nothing to show (docs/20 §2.2).
    $patterns = $shifts->map(fn ($bands) => $bands
        ->sortBy('day_of_week')
        ->map(fn (Schedule $s) => $s->day_of_week.substr((string) $s->start_time, 0, 5))
        ->join(','))->values();

    expect($patterns->unique())->toHaveCount(4);

    // Somebody works Saturday, and only one somebody.
    $saturday = Schedule::withoutGlobalScopes()
        ->where('tenant_id', $id)
        ->where('schedulable_type', 'staff')
        ->where('day_of_week', 6)
        ->get();

    expect($saturday)->toHaveCount(1);

    // --- the catalogue -----------------------------------------------------
    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get();

    expect($services)->toHaveCount(10)
        ->and($services->pluck('booking_mode')->unique()->all())->toBe([BookingMode::DurationBased])
        // Genuinely different lengths — a catalogue of identical hours would
        // hide what the scheduler is good at.
        ->and($services->pluck('duration_minutes')->unique()->count())->toBeGreaterThan(3);

    $women = $services->firstWhere('name', 'Női hajvágás');

    expect($women->price_minor)->toBe(1_250_000)
        // Both stylists provide it — this is what makes "anyone" bookable.
        ->and($women->staff()->count())->toBe(2);

    // --- branding, and the switch that makes it visible ---------------------
    expect($tenant->branding['primary_color'])->toBe('#b0476b');

    // ⚠️ The JSON alone would do nothing: feature_branding is OFF by default on
    // the base plan, so the persona also writes the per-tenant override a
    // superadmin would — and thereby demonstrates that path too.
    expect(app(FeatureResolver::class)->enabled($tenant, Feature::Branding))->toBeTrue();

    // --- the salon's own voice on the confirmation mail ---------------------
    $template = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $id)->sole();

    expect($template->key)->toBe(NotificationType::BookingConfirmed)
        ->and($template->enabled)->toBeTrue()
        ->and($template->subject)->toContain('GlamZone');

    // --- the receptionist, and what she cannot reach ------------------------
    $manager = User::withoutGlobalScopes()->where('email', 'recepcio@'.$tenant->slug.'.demo.slot4u.hu')->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);

    expect($manager->hasRole(Role::Manager->value))->toBeTrue();

    // The role is only a demo of anything if somebody can sign in and find the
    // door locked (docs/20 §2.2 AC).
    $this->actingAs($manager)->get(tenantHost($tenant->slug, '/reports'))->assertOk();
    $this->actingAs($manager)->get(tenantHost($tenant->slug, '/settings'))->assertForbidden();
});

// --- It can be booked on ---------------------------------------------------

it('lets a visitor pick a stylist or leave it to the salon', function () {
    $tenant = glamzone();
    $slug = $tenant->slug;

    $this->get(tenantHost($slug, '/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.name', 'GlamZone Szépségszalon')
            // Three categories, so the catalogue reads like a price list.
            ->has('categories', 3));

    $women = Service::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('name', 'Női hajvágás')
        ->sole();

    $stylist = Staff::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('name', 'Kovács Réka')
        ->sole();

    // ⚠️ Deliberately NOT the dateless `/book`. The picker lands on today
    // (BuildsSlotView) and nobody in this salon works a Sunday, so asking about
    // the landing day was an assertion that held six days a week and failed on
    // the seventh — it sat green on `main` until a Sunday CI run tripped over it.
    // Ask across a full week instead, and require the public grid to agree with
    // the rota in BOTH directions.
    //
    // The days are those of THIS service's own stylists, not the salon's — the
    // salon is open on a Saturday, but only the nail specialist works it, so a
    // women's haircut is legitimately unbookable that day. Scoping to the
    // service's staff is also the sharper assertion: it fails if the
    // `service_staff` pivot ever syncs somebody who is not rostered.
    $openDays = Schedule::withoutGlobalScopes()
        ->where('schedulable_type', (new Staff)->getMorphClass())
        ->whereIn('schedulable_id', $women->staff()->pluck('staff.id'))
        ->distinct()
        ->pluck('day_of_week')
        ->map(fn ($day): int => (int) $day)
        ->all();

    // Réka's own days, which is where the pinned comparison below has to happen:
    // on a day she is off, "anyone" is bookable and she is not, and that is the
    // rota working rather than a bug.
    $herDays = Schedule::withoutGlobalScopes()
        ->where('schedulable_type', $stylist->getMorphClass())
        ->where('schedulable_id', $stylist->getKey())
        ->distinct()
        ->pluck('day_of_week')
        ->map(fn ($day): int => (int) $day)
        ->all();

    expect($openDays)->not->toBeEmpty('no stylist who cuts hair has any hours at all')
        ->and($herDays)->not->toBeEmpty('the stylist has no shifts at all');

    // From tomorrow: today's remaining hours can legitimately be used up.
    $day = Carbon::today($tenant->timezone)->addDay();
    $daysOffered = 0;
    $compared = false;

    for ($i = 0; $i < 7; $i++, $day->addDay()) {
        $date = $day->toDateString();

        // "Anyone": no staff pinned, so AvailabilityService offers the union of
        // every stylist's free windows.
        $anySlots = $this->get(tenantHost($slug, '/book?service='.$women->getKey().'&date='.$date))
            ->assertOk()
            ->viewData('page')['props']['slots'] ?? [];

        if (! in_array($day->isoWeekday(), $openDays, true)) {
            expect($anySlots)->toBeEmpty("a women's haircut is offered on {$date}, when no stylist who does one is rostered");

            continue;
        }

        $daysOffered += $anySlots === [] ? 0 : 1;

        if (! in_array($day->isoWeekday(), $herDays, true) || $anySlots === []) {
            continue;
        }

        // With one stylist pinned, still bookable — but a subset, since one
        // person cannot be free where nobody was.
        $pinnedSlots = $this->get(tenantHost(
            $slug,
            '/book?service='.$women->getKey().'&staff='.$stylist->getKey().'&date='.$date,
        ))->assertOk()->viewData('page')['props']['slots'] ?? [];

        expect(count($pinnedSlots))->toBeLessThanOrEqual(count($anySlots));
        $compared = true;
    }

    expect($daysOffered)->toBeGreaterThan(0, 'no women\'s haircut is bookable on any rostered day of the coming week')
        ->and($compared)->toBeTrue('the stylist was never free on one of her own shifts all week');
});

// --- The numbers -----------------------------------------------------------

it('feeds the statistics module with half a year of coherent trade', function () {
    $tenant = glamzone();
    $id = $tenant->getKey();

    $timed = glamzoneBookings($tenant)->whereNotNull('starts_at')->orderBy('starts_at')->get();
    $past = $timed->filter(fn (Booking $b): bool => $b->starts_at->isPast());

    // Half a year of it — what "6 hónapos görbék" needs (docs/20 §2.2 AC).
    expect($past->count())->toBeGreaterThan(600)
        ->and($past->first()->starts_at->diffInDays(now()))->toBeGreaterThan(150)
        ->and($timed->filter(fn (Booking $b): bool => $b->starts_at->isFuture()))->not->toBeEmpty()
        // No past appointment left hanging: month-old "confirmed" rows would
        // both look abandoned and count revenue that may never have happened.
        ->and($past->every(fn (Booking $b): bool => $b->status->isTerminal()))->toBeTrue();

    // ⚠️ Never two people in one chair. The services run 30 to 150 minutes with
    // different buffers, so unlike the solo practice there is no tidy hourly
    // grid to lean on — the day planner's forward-only cursor is the guarantee,
    // and this is what pins it.
    foreach ($timed->whereIn('status', BookingStatus::occupyingValues())->groupBy('room_id') as $roomId => $inRoom) {
        $previous = null;
        foreach ($inRoom->sortBy('starts_at') as $booking) {
            if ($previous !== null) {
                expect($previous->ends_at->lte($booking->starts_at))->toBeTrue(
                    "Room {$roomId} double-booked: {$previous->starts_at} → {$previous->ends_at} vs {$booking->starts_at}"
                );
            }
            $previous = $booking;
        }
    }

    // Regulars, so the "top customers by spend" panel is a curve and not a
    // straight line — bounded at BOTH ends, which is the assertion that would
    // have caught the roster being too small.
    //
    // docs/20 §2.2 asks for 35 customers AND 6–10 bookings a day for half a
    // year; those cannot both hold (35 people would each need an appointment
    // every four days), and seeded literally the top client had 135 visits.
    // The roster is 180 instead, so "regular" means monthly rather than absurd.
    $perCustomer = $timed->groupBy('customer_id')->map->count()->sortDesc();

    expect($perCustomer->first())->toBeGreaterThanOrEqual(15)
        ->and($perCustomer->first())->toBeLessThan(60)
        // And a long tail behind them, not 180 people with identical diaries.
        ->and($perCustomer->median())->toBeLessThan($perCustomer->first() / 2);

    // Every resource earns: an idle staff member or room would leave a blank
    // row in the utilisation report the persona exists to fill.
    expect($timed->pluck('staff_id')->unique())->toHaveCount(4)
        ->and($timed->pluck('room_id')->unique())->toHaveCount(4);

    // Plan-consistent (docs/20 §1.6) — the visitor has to be able to rebuild
    // this in their own account, which is only true under the raised base
    // ceilings of SLO-195.
    $limits = app(PlanLimitService::class);

    expect($limits->withinLimit(PlanLimitKey::MaxEmployees, Staff::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxRooms, Room::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxLocations, Location::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue();

    // And the report itself renders on real numbers rather than zeroes.
    $owner = User::withoutGlobalScopes()->where('email', (new SalonDemoPersona)->adminEmail())->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($id);

    $this->actingAs($owner)
        ->get(tenantHost($tenant->slug, '/reports?preset=last_30_days'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.bookings', fn (int $n): bool => $n > 0)
            ->where('report.totals.revenue_minor', fn (int $n): bool => $n > 0)
            ->has('report.by_staff', 4)
            ->has('report.top_customers'));
});
