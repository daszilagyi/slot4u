<?php

use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\Schedule;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Settings\TenantLanding;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The glam landing template (SLO-241, docs/26)
|--------------------------------------------------------------------------
|
| The salon page. Its content only NAMES the categories, services and people
| to feature; what is shown about them — the title, the price, the free times —
| comes from the tenant's real records.
|
*/

beforeEach(function () {
    // Monday 2026-09-07, 08:00 UTC = 10:00 in Budapest.
    Carbon::setTestNow('2026-09-07 08:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
    app(TenantManager::class)->forget();
});

/** @return array<string, mixed> */
function glamContent(array $overrides = []): array
{
    return array_merge([
        'template' => 'glam',
        'brand_title' => 'Csillag',
        'headline' => ['Ragyogj.', 'Mi intézzük', 'az időpontot.', 'negyedik sor'],
        'neon' => ["Good\nHair", 'Beauty'],
        'featured' => [['name' => 'Vágás', 'badge' => 'Népszerű', 'photo' => 'svc-cut']],
        'categories' => [['name' => 'Haj', 'subtitle' => 'Vágás', 'icon' => 'scissors', 'photo' => 'cat-hair']],
        'team' => [
            ['name' => 'Nincs Ilyen', 'skills' => 'x', 'photo' => 'staff-9'],
            ['name' => 'Kovács Réka', 'skills' => 'Vágás · festés', 'photo' => 'staff-1'],
        ],
        'quick_service' => 'Vágás',
    ], $overrides);
}

/**
 * A glam tenant with a 30-minute service, one stylist on it, and her Monday
 * shift from 9 to 12 (Budapest).
 *
 * @return array{0: Tenant, 1: Service, 2: Staff}
 */
function glamSalon(array $content = []): array
{
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'landing' => glamContent($content)]);
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create([
        'name' => 'Vágás',
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 30,
        'active' => true,
    ]);
    $staff = Staff::factory()->forTenant($tenant)->create(['name' => 'Kovács Réka', 'title' => 'fodrász', 'active' => true]);
    $service->staff()->attach($staff->id);

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(1, '09:00', '12:00')->create();
    app(TenantManager::class)->forget();

    return [$tenant, $service, $staff];
}

it('hands the glam template its content, its team and today\'s free times', function () {
    [, $service, $staff] = glamSalon();

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Tenant/Home')
            ->where('landing.template', 'glam')
            // Three headline lines at most.
            ->where('landing.headline', ['Ragyogj.', 'Mi intézzük', 'az időpontot.'])
            ->where('landing.featured.0.photo', 'svc-cut')
            // The unknown stylist drops out; the real one keeps her real title.
            ->has('glam.team', 1)
            ->where('glam.team.0.id', $staff->id)
            ->where('glam.team.0.title', 'fodrász')
            ->where('glam.team.0.skills', 'Vágás · festés')
            ->where('glam.quick.service_id', $service->id)
            ->where('glam.quick.date', '2026-09-07')
            ->where('glam.quick.is_today', true)
            // 10:00 local is now: the 9:00 and 9:30 slots are gone.
            ->where('glam.quick.times', ['10:30', '11:00', '11:30']));
});

it('moves the free times to the next day with room once today is full', function () {
    [$tenant, $service, $staff] = glamSalon();
    Carbon::setTestNow('2026-09-07 10:00:00'); // 12:00 local — the shift is over.

    $this->get(tenantHost('acme'))
        ->assertInertia(fn (Assert $page) => $page
            // Next Monday, a week out: the stylist works Mondays only.
            ->where('glam.quick', null));

    Schedule::factory()->forTenant($tenant)->forSchedulable($staff)->onDay(2, '09:00', '10:00')->create();

    $this->get(tenantHost('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('glam.quick.date', '2026-09-08')
            ->where('glam.quick.is_tomorrow', true)
            ->where('glam.quick.times', ['09:00', '09:30']));
});

it('leaves a booked time off the strip', function () {
    [$tenant, $service, $staff] = glamSalon();

    Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'staff_id' => $staff->id,
        'starts_at' => '2026-09-07 08:30:00',
        'ends_at' => '2026-09-07 09:00:00',
    ]);

    $this->get(tenantHost('acme'))
        ->assertInertia(fn (Assert $page) => $page->where('glam.quick.times', ['11:00', '11:30']));
});

it('drops a card whose person has left, and a strip whose service is gone', function () {
    [, $service, $staff] = glamSalon();
    $staff->update(['active' => false]);
    $service->update(['active' => false]);

    $this->get(tenantHost('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('glam.team', [])
            ->where('glam.quick', null));
});

it('computes nothing for a tenant on another template', function () {
    Tenant::factory()->active()->create(['slug' => 'acme', 'landing' => ['template' => 'calm']]);

    $this->get(tenantHost('acme'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('landing.template', 'calm')
            ->where('glam', null));
});

it('never puts another tenant\'s stylist on the page', function () {
    glamSalon();
    $other = Tenant::factory()->active()->create(['slug' => 'other', 'landing' => glamContent()]);
    // Same name, other tenant: the team card must not find her.

    $this->get(tenantHost('other'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('landing.template', 'glam')
            ->where('glam.team', [])
            ->where('glam.quick', null));

    expect($other->id)->not->toBeNull();
});

it('reads glam content defensively', function () {
    $landing = TenantLanding::fromArray([
        'template' => 'glam',
        'neon' => ['egy', 'kettő', 'három'],
        'categories' => [
            ['name' => 'Haj', 'icon' => 'rocket', 'photo' => '../../etc/passwd'],
            ['subtitle' => 'név nélkül'],
            ['name' => 'Köröm', 'icon' => 'hand', 'photo' => 'https://evil.test/x.png'],
        ],
        'featured' => [['name' => 'Vágás', 'badge' => ['nem', 'szöveg'], 'photo' => 'svc-cut']],
        'team' => 'nem lista',
        'quick_service' => 42,
    ]);

    expect($landing->usesGlam())->toBeTrue()
        ->and($landing->neon)->toBe(['egy', 'kettő'])
        // A photo is a slot key, never a path or a URL.
        ->and($landing->categoryCards)->toBe([
            ['name' => 'Haj', 'subtitle' => '', 'icon' => 'leaf', 'photo' => null],
            ['name' => 'Köröm', 'subtitle' => '', 'icon' => 'hand', 'photo' => null],
        ])
        ->and($landing->featured)->toBe([['name' => 'Vágás', 'badge' => null, 'description' => null, 'photo' => 'svc-cut']])
        ->and($landing->team)->toBe([])
        ->and($landing->quickService)->toBeNull();
});
