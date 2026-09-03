<?php

use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\QuoteRequestStatus;
use App\Models\Booking;
use App\Models\Location;
use App\Models\QuoteRequest;
use App\Models\Room;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\Demo\VenueDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| „Fényliget Rendezvényház" (SLO-186, docs/20 §2.4)
|--------------------------------------------------------------------------
|
| The quote-driven persona — the one that proves slot4u is not merely an
| appointment book. What is pinned here is the whole of `quote_request`
| (docs/04 §6): every status in the pipeline present at once, an accepted
| enquiry that produced a real engagement at the quoted price, a conversation
| on the ones that had one — plus the resource-rental-with-approval combination
| that exists nowhere else in the demo set.
|
| Grouped into three tests for the reason given in SalonPersonaTest: the seed
| is paid per test by RefreshDatabase.
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

function venue(): Tenant
{
    test()->artisan('demo:seed', ['--tenant' => (new VenueDemoPersona)->slug()])->assertSuccessful();

    return Tenant::withoutGlobalScopes()->where('slug', (new VenueDemoPersona)->slug())->sole();
}

/**
 * @return Builder<QuoteRequest>
 */
function venueQuotes(Tenant $tenant)
{
    return QuoteRequest::withoutGlobalScopes()->where('tenant_id', $tenant->getKey());
}

// --- The venue -------------------------------------------------------------

it('builds the venue the data sheet describes', function () {
    $tenant = venue();
    $id = $tenant->getKey();

    expect($tenant->is_demo)->toBeTrue()
        ->and(Location::withoutGlobalScopes()->where('tenant_id', $id)->count())->toBe(1);

    // Three rooms, and the capacities are the product: a 120-seat hall is what
    // makes an enquiry about 90 guests answerable.
    $rooms = Room::withoutGlobalScopes()->where('tenant_id', $id)->get()->pluck('capacity', 'name');

    expect($rooms->all())->toBe([
        'Nagyterem' => 120,
        'Panoráma terasz' => 60,
        'Tárgyaló' => 20,
    ]);

    $staff = Staff::withoutGlobalScopes()->where('tenant_id', $id)->get();
    $admin = User::withoutGlobalScopes()->where('email', (new VenueDemoPersona)->adminEmail())->sole();

    expect($staff)->toHaveCount(2)
        // The organiser IS the admin — she is who a site visit is booked with,
        // so her account and her calendar have to be the same person.
        ->and($staff->firstWhere('name', 'Halász Villő')->user_id)->toBe($admin->getKey())
        ->and($staff->firstWhere('name', 'Németh Zsolt')->user_id)->toBeNull();

    $services = Service::withoutGlobalScopes()->where('tenant_id', $id)->get()->keyBy('booking_mode.value');

    expect($services)->toHaveCount(3);

    // The enquiry carries the form's schema; without it the public page would
    // render a quote form with no questions on it.
    expect($services[BookingMode::QuoteRequest->value]->quoteFields())->toBe([
        'Tervezett dátum', 'Várható létszám', 'Esemény típusa', 'Catering igény',
    ]);

    // ⚠️ Resource rental AND approval — the combination docs/20 §2.4 asks for,
    // and the only place in the demo set where the two meet.
    $meeting = $services[BookingMode::ResourceRental->value];

    expect($meeting->requires_approval)->toBeTrue()
        ->and($meeting->price_minor)->toBe(1_500_000)
        // Fixed at an hour on purpose: price_minor is a flat per-booking
        // snapshot, so an hourly rate is only truthful while the booking IS an
        // hour (duration-scaled pricing is SLO-193).
        ->and($meeting->duration_minutes)->toBe(60);

    // The walk-through is free — charging for it would be charging for your own
    // sales call.
    expect($services[BookingMode::DurationBased->value]->price_minor)->toBe(0);
});

// --- The pipeline ----------------------------------------------------------

it('shows the whole quote pipeline at once, with real money behind the won ones', function () {
    $tenant = venue();

    // ⚠️ The acceptance criterion. Every status on screen together — an enquiry
    // list where everything is already closed demonstrates a feature with
    // nothing left to do.
    foreach (QuoteRequestStatus::cases() as $status) {
        expect(venueQuotes($tenant)->where('status', $status->value)->count())
            ->toBeGreaterThan(0, "No quote request left in status {$status->value}");
    }

    $accepted = venueQuotes($tenant)->where('status', QuoteRequestStatus::Accepted->value)->get();

    // Every accepted enquiry became an engagement, at the price that was
    // actually quoted rather than the service list price (docs/04 §6).
    expect($accepted)->not->toBeEmpty()
        ->and($accepted->every(fn (QuoteRequest $q): bool => $q->booking_id !== null))->toBeTrue();

    foreach ($accepted as $quote) {
        $booking = Booking::withoutGlobalScopes()->findOrFail($quote->booking_id);

        expect($booking->price_minor)->toBe($quote->price_minor)
            ->and($booking->booking_mode)->toBe(BookingMode::QuoteRequest)
            // A quote booking is never time-slotted, which is why the reports
            // date it by created_at instead.
            ->and($booking->starts_at)->toBeNull();
    }

    // The open quote is still open: an expired offer on the demo's front page
    // is a dead end.
    $quoted = venueQuotes($tenant)->where('status', QuoteRequestStatus::Quoted->value)->first();

    expect($quoted->price_minor)->toBeGreaterThan(0)
        ->and($quoted->valid_until->isFuture())->toBeTrue();

    // A conversation on at least one enquiry (docs/20 §2.4), with both sides
    // speaking — a thread of only tenant replies is not a conversation.
    $messages = DB::table('quote_request_messages')
        ->whereIn('quote_request_id', venueQuotes($tenant)->pluck('id'))
        ->get();

    expect($messages)->not->toBeEmpty()
        ->and($messages->whereNotNull('user_id')->pluck('user_id')->unique()->count())->toBeGreaterThan(1);

    // The parameters read like a real enquiry, not a blank form.
    $withParams = venueQuotes($tenant)->whereNotNull('parameters')->first();

    expect(array_keys($withParams->parameters))->toBe([
        'Tervezett dátum', 'Várható létszám', 'Esemény típusa', 'Catering igény',
    ]);

    // "Few bookings, large amounts" — the statistics shape docs/20 §2.4 exists
    // to show. A demo that only ever showed high-frequency low-ticket trade
    // would quietly tell every venue that the product is not for them.
    $revenue = $accepted->sum('price_minor');
    $bookings = Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count();

    expect($revenue)->toBeGreaterThan(1_000_000_00)
        ->and(intdiv($revenue, max(1, $bookings)))->toBeGreaterThan(10_000_00);
});

// --- Public, and the approval hold -----------------------------------------

it('takes an enquiry from the public page and holds an unapproved room let', function () {
    $tenant = venue();
    $slug = $tenant->slug;

    $this->get(tenantHost($slug, '/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('profile.name', 'Fényliget Rendezvényház'));

    $enquiry = Service::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('booking_mode', BookingMode::QuoteRequest)
        ->sole();

    // The public form has to render its questions — the mode's whole surface.
    $this->get(tenantHost($slug, '/book?service='.$enquiry->getKey()))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('service.booking_mode', BookingMode::QuoteRequest->value)
            ->where('quote_enabled', true)
            ->has('quote_fields', 4));

    // And accept one, which is the acceptance criterion: a visitor can enquire.
    $before = venueQuotes($tenant)->count();

    $this->post(tenantHost($slug, '/quote'), [
        'service_id' => $enquiry->getKey(),
        'name' => 'Próba Andrea',
        'email' => 'proba.andrea@example.test',
        'phone' => '+36301234567',
        'notes' => 'Kétnapos konferenciát tervezünk.',
        'fields' => ['2026-11-14', '80 fő', 'Konferencia', 'Kávészünet'],
    ])->assertRedirect();

    expect(venueQuotes($tenant)->count())->toBe($before + 1);

    // ⚠️ The rental approval hold. A `requested` booking only holds its slot for
    // the tenant's approval window (48h), so this one is seeded a day out — any
    // older and the expiry sweep would cancel it the first time the scheduler
    // runs on staging, and the demo would lose it overnight (SLO-26).
    $pending = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('status', BookingStatus::Requested)
        ->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->booking_mode)->toBe(BookingMode::ResourceRental)
        ->and($pending->first()->hold_expires_at)->not->toBeNull()
        ->and($pending->first()->hold_expires_at->isFuture())->toBeTrue()
        // It books the ROOM, not a person — nobody staffs a meeting room, which
        // is also why these never contend with a site visit for the organiser.
        ->and($pending->first()->room_id)->not->toBeNull()
        ->and($pending->first()->staff_id)->toBeNull();

    // No two lets of the one meeting room overlap.
    $lets = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('booking_mode', BookingMode::ResourceRental)
        ->whereIn('status', BookingStatus::occupyingValues())
        ->orderBy('starts_at')
        ->get();

    $previous = null;
    foreach ($lets as $let) {
        if ($previous !== null) {
            expect($previous->ends_at->lte($let->starts_at))->toBeTrue(
                "Meeting room double-let: {$previous->starts_at} → {$previous->ends_at} vs {$let->starts_at}"
            );
        }
        $previous = $let;
    }
});

it('builds on whatever weekday the nightly reset happens to run', function (string $today) {
    // Every date is an offset from "today" (docs/20 §1.3), and this persona
    // places its pending room let on a weekday it has to walk forward to find.
    // The staging reset (SLO-191) runs on Saturdays too, and a persona that only
    // builds Monday-to-Friday fails at 03:00 on a day nobody is watching.
    Carbon::setTestNow(Carbon::parse($today.' 03:00', 'Europe/Budapest'));

    $tenant = venue();

    // The pipeline still shows every stage, and the hold is still live —
    // the two things a shifted week could quietly break.
    foreach (QuoteRequestStatus::cases() as $status) {
        expect(venueQuotes($tenant)->where('status', $status->value)->count())
            ->toBeGreaterThan(0, "No quote request in status {$status->value} when seeded on {$today}");
    }

    $pending = Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->where('status', BookingStatus::Requested)
        ->sole();

    expect($pending->hold_expires_at->isFuture())->toBeTrue()
        ->and($pending->starts_at->isFuture())->toBeTrue();

    // The cause, not just the symptom: no enquiry may be dated in the future.
    // One that is gets dropped by the pipeline builder, and the missing status
    // above is only how that shows up.
    expect(venueQuotes($tenant)->get()->every(fn ($q): bool => $q->created_at->isPast()))
        ->toBeTrue('An enquiry was dated in the future and would have been skipped');
})->with([
    // A whole week, and every one of them at 03:00 — the hour the nightly reset
    // actually runs (SLO-191). Four sampled days used to be enough to pass and
    // not enough to catch: the freshest week's enquiries were drawn anywhere
    // from 0 to 6 days back, and an "earlier today" instant is in the FUTURE at
    // 03:00, so the enquiry was dropped and the pipeline lost a status. It hit
    // about one run in three and got through review on a green local suite.
    'Monday' => '2026-09-07',
    'Tuesday' => '2026-09-08',
    'Wednesday' => '2026-09-09',
    'Thursday' => '2026-09-10',
    'Friday' => '2026-09-11',
    'Saturday' => '2026-09-12',
    'Sunday' => '2026-09-13',
]);
