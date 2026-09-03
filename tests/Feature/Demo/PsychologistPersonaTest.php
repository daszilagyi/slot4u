<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\PlanLimitKey;
use App\Enums\ScheduleExceptionType;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\PsychologistDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| „Lélekút Pszichológiai Rendelő" (SLO-184, docs/20 §2.1)
|--------------------------------------------------------------------------
|
| The first of the four sales personas, and therefore the first real test of
| the SLO-183 framework. The framework's own properties — determinism,
| idempotency, backdating, the purge guardrails — are pinned in
| DemoSeedFrameworkTest; what is asserted here is what makes this particular
| demo demonstrable:
|
|   the practice matches its data sheet, its public page can actually be
|   booked on, the approval flow has something pending to approve, the seeded
|   world is internally consistent (no double-booked hour, no plan limit
|   exceeded), and — the one that is not a technical property at all — no note
|   anywhere carries health-related content.
|
| Every test seeds ONLY this persona: the suite has no reason to rebuild the
| smoke tenant to ask about this one.
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

/** Seed the persona and hand back its tenant. */
function lelekut(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new PsychologistDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()
        ->where('slug', (new PsychologistDemoPersona)->slug())
        ->sole();
}

/**
 * @return Builder<Booking>
 */
function lelekutBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
}

// --- The data sheet (docs/20 §2.1) -----------------------------------------

it('builds the practice the data sheet describes', function () {
    $tenant = lelekut();
    $id = $tenant->getKey();

    expect($tenant->is_demo)->toBeTrue()
        ->and($tenant->timezone)->toBe('Europe/Budapest')
        // The public homepage renders the profile and nothing else — a demo
        // business with no address is the least convincing kind.
        ->and($tenant->settings['address_city'] ?? null)->toBe('Budapest');

    expect(Location::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(1)
        ->and(Room::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(1);

    $staff = Staff::withoutGlobalScopes()->where('tenant_id', $id)->sole();
    $admin = User::withoutGlobalScopes()->where('email', (new PsychologistDemoPersona)->adminEmail())->sole();

    // The practitioner IS the tenant admin (docs/20 §2.1) — the solo persona's
    // defining structural detail, and the reason `staff.user_id` is filled.
    expect($staff->name)->toBe('dr. Vas Emese')
        ->and($staff->user_id)->toBe($admin->getKey())
        ->and($admin->name)->toBe('dr. Vas Emese');

    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get()->keyBy('name');

    expect($services)->toHaveCount(4);

    // Prices in minor units, in the real Hungarian market band (docs/20 §5.4):
    // the visitor does the arithmetic in their head, and it has to add up.
    expect($services['Első konzultáció']->price_minor)->toBe(2_200_000)
        ->and($services['Egyéni konzultáció']->price_minor)->toBe(1_800_000)
        ->and($services['Online konzultáció']->price_minor)->toBe(1_600_000)
        ->and($services['Igazolás / dokumentum kérése']->price_minor)->toBe(500_000);

    expect($services['Első konzultáció']->requires_approval)->toBeTrue()
        ->and($services['Egyéni konzultáció']->requires_approval)->toBeFalse()
        ->and($services['Igazolás / dokumentum kérése']->booking_mode)->toBe(BookingMode::NoTimeSlot)
        ->and($services['Igazolás / dokumentum kérése']->settings['fulfillment_type'])->toBe('manual');

    // Every session service needs its staff pivot, or the availability engine
    // has no resource to build a grid from and the public page offers nothing.
    foreach (['Első konzultáció', 'Egyéni konzultáció', 'Online konzultáció'] as $name) {
        expect($services[$name]->staff()->count())->toBe(1)
            ->and($services[$name]->duration_minutes)->toBe(50);
    }
});

it('opens Monday to Thursday until five and Friday until one', function () {
    $tenant = lelekut();

    $bands = Schedule::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('schedulable_type', 'staff')
        ->orderBy('day_of_week')
        ->get()
        ->mapWithKeys(fn (Schedule $s) => [$s->day_of_week => substr((string) $s->start_time, 0, 5).'-'.substr((string) $s->end_time, 0, 5)]);

    expect($bands->all())->toBe([
        1 => '09:00-17:00',
        2 => '09:00-17:00',
        3 => '09:00-17:00',
        4 => '09:00-17:00',
        5 => '09:00-13:00',
    ]);

    // The room carries the same bands: the public calendar lets a visitor filter
    // by room, and a room with no schedule has no free windows to offer.
    expect(Schedule::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('schedulable_type', 'room')
        ->count())->toBe(5);
});

it('closes one day ahead so the calendar shows an exception, not an unbroken grid', function () {
    $tenant = lelekut();

    $exception = ScheduleException::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->sole();

    expect($exception->type)->toBe(ScheduleExceptionType::Off)
        ->and($exception->date->isFuture())->toBeTrue()
        // A whole-day closure: null start/end (docs/02).
        ->and($exception->start_time)->toBeNull()
        ->and($exception->end_time)->toBeNull();

    // And nothing is booked on it — a booking on a closed day is an orphan the
    // availability engine will never offer back.
    $closed = $exception->date->toDateString();
    $onClosedDay = lelekutBookings($tenant)
        ->whereIn('status', BookingStatus::occupyingValues())
        ->get()
        ->filter(fn (Booking $b): bool => $b->starts_at?->copy()->timezone($tenant->timezone)->toDateString() === $closed);

    expect($onClosedDay)->toBeEmpty();
});

// --- It is demonstrable ----------------------------------------------------

it('serves a public page a visitor can actually book on', function () {
    $tenant = lelekut();
    $slug = (new PsychologistDemoPersona)->slug();

    $this->get(tenantHost($slug, '/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.name', 'Lélekút Pszichológiai Rendelő')
            // Four services across the two categories.
            ->where('categories.0.services.0.name', 'Egyéni konzultáció'));

    // The acceptance criterion that matters: free slots, not merely a page.
    $this->get(tenantHost($slug, '/book'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('slots', fn (Assert $slots) => $slots->etc()));

    $slots = $this->get(tenantHost($slug, '/book'))->viewData('page')['props']['slots'] ?? [];

    expect($slots)->not->toBeEmpty();
});

it('offers the document order as a service with no time slot', function () {
    $tenant = lelekut();

    $document = Service::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('booking_mode', BookingMode::NoTimeSlot)
        ->sole();

    $this->get(tenantHost((new PsychologistDemoPersona)->slug(), '/book?service='.$document->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('service.booking_mode', BookingMode::NoTimeSlot->value)
            // The public order form branches on this (Book.tsx): `manual` shows
            // the "we will get back to you" copy rather than a download link.
            ->where('service.fulfillment_type', 'manual'));

    // Seeded orders exist, and one is still open — an empty fulfilment queue
    // would show the admin an empty screen on the very feature being demoed.
    $orders = lelekutBookings($tenant)->where('booking_mode', BookingMode::NoTimeSlot->value)->get();

    expect($orders)->not->toBeEmpty()
        ->and($orders->contains(fn (Booking $b): bool => $b->status === BookingStatus::Confirmed))->toBeTrue()
        ->and($orders->contains(fn (Booking $b): bool => $b->status === BookingStatus::Completed))->toBeTrue();
});

it('leaves a live approval decision waiting on the admin screen', function () {
    $tenant = lelekut();

    $pending = lelekutBookings($tenant)->where('status', BookingStatus::Requested->value)->get();
    $rejected = lelekutBookings($tenant)->where('status', BookingStatus::Rejected->value)->get();

    expect($pending)->toHaveCount(1)
        ->and($rejected)->not->toBeEmpty()
        // A rejection carries its reason — it is a business rule of the mode,
        // not a form nicety (RejectBooking).
        ->and($rejected->first()->reject_reason)->not->toBeNull();

    // ⚠️ The soft hold must still be open. A `requested` booking seeded further
    // back than the tenant's approval window would be a pending decision that
    // the expiry sweep cancels the first time the scheduler runs on staging —
    // the demo would go quietly empty overnight (SLO-26).
    expect($pending->first()->hold_expires_at)->not->toBeNull()
        ->and($pending->first()->hold_expires_at->isFuture())->toBeTrue();

    // And it is visible where the demo is given: the admin's booking list.
    $admin = User::withoutGlobalScopes()->where('email', (new PsychologistDemoPersona)->adminEmail())->sole();
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    $this->actingAs($admin)
        ->get(tenantHost((new PsychologistDemoPersona)->slug(), '/bookings?status='.BookingStatus::Requested->value))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('bookings.data', 1));
});

it('fills a quarter of a year behind it and a fortnight ahead', function () {
    $tenant = lelekut();

    $timed = lelekutBookings($tenant)->whereNotNull('starts_at')->get();
    $past = $timed->filter(fn (Booking $b): bool => $b->starts_at->isPast());
    $future = $timed->filter(fn (Booking $b): bool => $b->starts_at->isFuture());

    // docs/20 §2.1 asks for 8–12 a week over ~90 days; the point of the number
    // is that the dashboard and the customer histories have something to show.
    expect($past->count())->toBeGreaterThan(80)
        ->and($future)->not->toBeEmpty()
        ->and($past->min('starts_at')->diffInDays(now()))->toBeGreaterThan(60);

    // No past appointment left hanging: a calendar full of month-old
    // "confirmed" rows is what an abandoned system looks like.
    expect($past->every(fn (Booking $b): bool => $b->status->isTerminal()))->toBeTrue();

    // The whole customer roster, and every one of them a real account rather
    // than a guest — the admin's customer list is part of the demo.
    expect(DB::table('bookings')->where('tenant_id', $tenant->getKey())->whereNull('customer_id')->count())->toBe(0);
});

// --- Invariants ------------------------------------------------------------

it('never double-books the one consulting room', function () {
    $tenant = lelekut();

    $occupying = lelekutBookings($tenant)
        ->whereNotNull('starts_at')
        ->whereIn('status', BookingStatus::occupyingValues())
        ->orderBy('starts_at')
        ->get();

    expect($occupying)->not->toBeEmpty();

    $previous = null;
    foreach ($occupying as $booking) {
        if ($previous !== null) {
            // One practitioner, one room: every appointment must end before the
            // next begins. The seed builds on a whole-hour grid precisely so
            // this holds without the conflict check ever having to reject one.
            expect($previous->ends_at->lte($booking->starts_at))->toBeTrue(
                "Overlap: {$previous->starts_at} → {$previous->ends_at} vs {$booking->starts_at}"
            );
        }
        $previous = $booking;
    }
});

it('stays inside the plan limits it is meant to illustrate', function () {
    $tenant = lelekut();
    $id = $tenant->getKey();
    $limits = app(PlanLimitService::class);

    // The persona is the small end of the product (docs/20 §2.1), so it must fit
    // the free base plan with room to spare — a demo that could not be built by
    // the visitor's own account would be selling something that does not exist.
    expect($limits->withinLimit(PlanLimitKey::MaxLocations, Location::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxRooms, Room::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue()
        ->and($limits->withinLimit(PlanLimitKey::MaxEmployees, Staff::withoutGlobalScopes()->where('tenant_id', $id)->count() - 1))->toBeTrue();
});

// --- ⚠️ The content rule ---------------------------------------------------

it('⚠️ writes no health-related content into any note, not even fictionally', function () {
    $tenant = lelekut();

    // docs/20 §2.1 and §5.5. A note is where a real practice would write the
    // sensitive thing, so it is where a demo must visibly not — anything
    // resembling a symptom, a diagnosis or a reason for attending is a special
    // category of personal data by association, and inventing one would be
    // advertising the wrong instinct to the sector most sensitive to it.
    //
    // The blocklist covers the free text the SEED writes (notes, rejection
    // reasons). The practice's own name and service catalogue necessarily say
    // "pszichológiai" and are not in scope — what is forbidden is content ABOUT
    // a client, not the fact that the business exists.
    $forbidden = [
        'diagnó', 'tünet', 'terápi', 'kezelés', 'gyógyszer', 'szorong', 'depress',
        'pánik', 'trauma', 'krízis', 'beteg', 'zavar', 'stressz', 'alvás', 'bno',
    ];

    $freeText = lelekutBookings($tenant)
        ->get(['notes', 'reject_reason'])
        ->flatMap(fn (Booking $b): array => [$b->notes, $b->reject_reason])
        ->filter()
        ->values();

    expect($freeText)->not->toBeEmpty();

    foreach ($freeText as $text) {
        foreach ($forbidden as $word) {
            expect(mb_strtolower($text))->not->toContain($word);
        }
    }
});

it('builds on whatever weekday the nightly reset happens to run', function (string $today) {
    // Every date in the seed is an offset from "today" (docs/20 §1.3), so the
    // shape of the week is different on each run: the day-off lands elsewhere,
    // the pending request has to skip a weekend, and Friday's short day moves
    // through the history window. The nightly staging reset (SLO-191) runs on
    // Saturdays too, and a persona that only builds Monday-to-Friday would fail
    // at 03:00 on a day nobody is watching.
    Carbon::setTestNow(Carbon::parse($today.' 03:00', 'Europe/Budapest'));

    $tenant = lelekut();

    expect(lelekutBookings($tenant)->count())->toBeGreaterThan(50)
        // The two showcase bookings are placed by hand onto open days; they are
        // what the weekend walk in nextOpenDay()/previousOpenDay() is for.
        ->and(lelekutBookings($tenant)->where('status', BookingStatus::Requested->value)->count())->toBe(1)
        ->and(lelekutBookings($tenant)->where('status', BookingStatus::Rejected->value)->count())->toBe(1);
})->with([
    'Monday' => '2026-09-07',
    'Friday' => '2026-09-11',
    'Saturday' => '2026-09-12',
    'Sunday' => '2026-09-13',
]);
