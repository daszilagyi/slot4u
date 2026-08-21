<?php

use App\Models\CommissionSetting;
use App\Models\LegalDocument;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plan\PlanLimitService;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\CommissionSettingSeeder;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ProductionSeeder;
use Database\Seeders\TenantDemoSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The required-data seed (SLO-166)
|--------------------------------------------------------------------------
|
| Missing catalogue data does not crash anything — it makes a feature politely do
| nothing, with every check still green. That is how the consent machinery
| reached production and sat inert. So what is tested here is mostly the boring
| part: that the seed puts each piece there, that running it twice is harmless,
| and that no development fixture can ride along.
|
*/

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

it('leaves a blank environment able to work', function () {
    // Every one of these is something whose absence is silent: no permissions
    // and nobody can act inside a tenant; no plan and the limit layer has
    // nothing to measure; no commission config and nothing can be billed; no
    // legal document and sign-up asks for no acceptance at all.
    $this->seed(ProductionSeeder::class);

    expect(Permission::query()->count())->toBeGreaterThan(0)
        ->and(Plan::query()->where('code', PlanLimitService::BASE_PLAN_CODE)->exists())->toBeTrue()
        ->and(CommissionSetting::query()->exists())->toBeTrue()
        ->and(LegalDocument::query()->platform()->count())->toBe(2);
});

it('can run again without duplicating anything', function () {
    // The deploy runs this on every release, so "twice" is the normal case, not
    // an edge one.
    $this->seed(ProductionSeeder::class);

    $before = [
        Permission::query()->count(),
        Plan::query()->count(),
        CommissionSetting::query()->count(),
        LegalDocument::query()->count(),
    ];

    $this->seed(ProductionSeeder::class);

    expect([
        Permission::query()->count(),
        Plan::query()->count(),
        CommissionSetting::query()->count(),
        LegalDocument::query()->count(),
    ])->toBe($before);
});

it('creates no tenants and no users', function () {
    // The boundary that matters: this seed runs on a live host, and a demo
    // tenant or a placeholder login appearing there would be a security
    // incident, not an inconvenience.
    $this->seed(ProductionSeeder::class);

    expect(Tenant::withoutGlobalScopes()->withTrashed()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('keeps the demo seeder out of the required list', function () {
    // Asserted against the list rather than the effects, so the guard survives
    // a future demo seeder that happens to create nothing on an empty database.
    expect(ProductionSeeder::required())->not->toContain(TenantDemoSeeder::class);
});

it('names every seeder a working environment needs', function () {
    // The risk here is omission — a new piece of required data added as a
    // seeder and never added to this list. A test that pins the list is the
    // cheapest place for that to be noticed.
    expect(ProductionSeeder::required())->toBe([
        PermissionSeeder::class,
        BasePlanSeeder::class,
        CommissionSettingSeeder::class,
        LegalDocumentSeeder::class,
    ]);
});

it('is what the deploy script actually runs', function () {
    // The seed being correct is worth nothing if the deploy calls something
    // else. Read from the script, so renaming the class without updating the
    // deploy fails here rather than on a release night.
    $script = (string) file_get_contents(base_path('deploy/deploy.sh'));

    expect($script)->toContain('db:seed --class=ProductionSeeder --force')
        // Never the development seeder, whatever else changes.
        ->not->toContain('DatabaseSeeder');
});
