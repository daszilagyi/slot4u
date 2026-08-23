<?php

use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The N+1 guard (SLO-155)
|--------------------------------------------------------------------------
|
| The Definition of Done has demanded "N+1 checked on listing endpoints" since
| M2, and it was checked — by hand, by whoever remembered. This turns the next
| forgotten eager load into a test failure instead of a page that got slower in
| a way nobody can date.
|
| ⚠️ The guard is armed everywhere EXCEPT production. A relation somebody
| forgot to eager load is a slow page; the same relation throwing is a 500 on a
| booking form. That branch is not covered here — proving it would mean booting
| a second application as production, and re-running the provider's boot() would
| double-register every listener and gate. It is one readable expression in
| AppServiceProvider, and it is stated in docs/01.
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

it('is armed in every environment the suite runs in', function () {
    // Both halves matter: that the guard is on, and that it is on BECAUSE this
    // is not production rather than by accident.
    expect(app()->isProduction())->toBeFalse()
        ->and(Model::preventsLazyLoading())->toBeTrue();
});

it('turns a forgotten eager load into a failure', function () {
    $tenant = Tenant::factory()->active()->create();
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create();
    // TWO bookings, and that is not padding. Laravel arms the guard in
    // `Builder::hydrate` only when the query returned more than one row
    // (`count($items) > 1`), because one lazy load is not an N+1 — it is one
    // extra query. The guard catches the SHAPE this issue is about: a loop over
    // a collection, each iteration going back to the database.
    Booking::factory()->count(2)->forTenant($tenant)->create(['service_id' => $service->id]);

    $bookings = Booking::query()->get();

    expect(fn () => $bookings->map(fn (Booking $booking): string => $booking->service->name)->all())
        ->toThrow(LazyLoadingViolationException::class);
});

it('is satisfied by the eager load the listing endpoints already do', function () {
    $tenant = Tenant::factory()->active()->create();
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Hajvágás']);
    Booking::factory()->count(2)->forTenant($tenant)->create(['service_id' => $service->id]);

    $bookings = Booking::query()->with('service')->get();

    expect($bookings->map(fn (Booking $booking): string => $booking->service->name)->all())
        ->toBe(['Hajvágás', 'Hajvágás']);
});

it('still allows an explicit load after the fact', function () {
    // The guard forbids ACCIDENTS, not lateness. `loadMissing` is how a service
    // that needs a relation the caller did not eager-load says so out loud —
    // which is what CustomerNotifier now does.
    $tenant = Tenant::factory()->active()->create();
    app(TenantManager::class)->set($tenant);

    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Hajvágás']);
    Booking::factory()->count(2)->forTenant($tenant)->create(['service_id' => $service->id]);

    $bookings = Booking::query()->get();
    $bookings->loadMissing('service');

    expect($bookings->first()->service->name)->toBe('Hajvágás');
});

it('indexes the tenant booking list on the columns it actually reads', function () {
    // `where tenant_id = ? order by starts_at desc` is the admin's main screen.
    // Without this index the query read every one of a tenant's bookings and
    // filesorted them — invisible on a demo tenant, the first thing to hurt on a
    // real one.
    $indexes = collect(Schema::getIndexes('bookings'))
        ->map(fn (array $index): array => $index['columns'])
        ->all();

    expect($indexes)->toContain(['tenant_id', 'starts_at']);
});
