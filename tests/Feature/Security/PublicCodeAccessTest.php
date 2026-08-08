<?php

use App\Enums\Feature;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;

/**
 * The code-addressable public surface (SLO-146, docs/01).
 *
 * `/booked/{code}`, `/booked/{code}/ics` and `/pay/{code}` are reachable without
 * logging in: the booking code *is* the credential, exactly like an unguessable
 * link in a confirmation email. That only holds while two things stay true — the
 * code is long enough not to be guessed, and the endpoints are rate limited so it
 * cannot be searched for. Both are asserted here rather than assumed.
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
});

afterEach(function () {
    app(TenantManager::class)->forget();
});

it('generates a booking code with enough entropy to be a credential', function () {
    $codes = collect(range(1, 200))->map(fn (): string => Booking::generateUniqueCode());

    // 8 characters from a 31-symbol alphabet ≈ 2^39.6 possibilities. Paired with
    // the 60/min throttle below, an exhaustive search averages millions of years;
    // shortening the code or widening the throttle breaks that arithmetic, so both
    // are pinned by tests.
    $codes->each(function (string $code) {
        expect(strlen($code))->toBe(8)
            ->and($code)->toMatch('/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/');
    });

    // Drawn from a CSPRNG (random_int), so 200 draws must not repeat.
    expect($codes->unique())->toHaveCount(200);
});

it('rate limits every code-addressable route', function () {
    // Without a throttle the entropy above is worth much less: an attacker could
    // walk the space instead of guessing it.
    $unthrottled = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getDomain(), '{tenant}'))
        ->filter(fn ($route) => str_contains($route->uri(), '{booking:code}')
            || str_contains($route->uri(), '{payment:provider_ref}'))
        ->reject(fn ($route) => collect($route->gatherMiddleware())
            ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unthrottled)->toBe([]);
});

it('answers an unknown code exactly like a foreign one', function () {
    // Both are 404: a "no such booking" that differed from "not yours" would turn
    // the confirmation page into an oracle for which codes exist.
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    $service = Service::factory()->forTenant($tenant)->create();
    $mine = Booking::factory()->forTenant($tenant)->create(['service_id' => $service->id]);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);
    $foreignService = Service::factory()->forTenant($other)->create();
    $foreign = Booking::factory()->forTenant($other)->create(['service_id' => $foreignService->id]);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme', '/booked/ZZZZZZZZ'))->assertNotFound();
    $this->get(tenantHost('acme', '/booked/'.$foreign->code))->assertNotFound();
    $this->get(tenantHost('acme', '/booked/'.$foreign->code.'/ics'))->assertNotFound();

    // The tenant's own code still works, so the 404s above are the scope talking
    // and not a broken route.
    $this->get(tenantHost('acme', '/booked/'.$mine->code))->assertOk();
});

it('answers an unknown payment code the same way', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    TenantFeature::factory()->create([
        'tenant_id' => $tenant->id,
        'feature_code' => Feature::OnlinePayment,
        'enabled' => true,
    ]);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme', '/pay/ZZZZZZZZ'))->assertNotFound();
});
