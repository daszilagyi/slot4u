<?php

use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use Database\Seeders\Demo\SmokeDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The nightly demo rebuild (SLO-191, docs/20 §3.2)
|--------------------------------------------------------------------------
|
| The demo is writable on purpose, so something has to undo what visitors do to
| it. What is tested here is the scheduling of that — that the entry exists, at
| the hour the personas are written for, and that it stays out of the way on a
| host with no demo at all — plus the reporting, because this is the one
| scheduled command whose silent failure is invisible: a half-built demo does
| not look broken, it looks like a product missing features.
|
| The content of each persona is tested by its own file; nothing here rebuilds
| the sales personas, which is why the assertions below only ever touch
| `demo-smoke`.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

/** The scheduled `demo:reset` entry, or null when nothing schedules it. */
function demoCronEvent(): ?Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, 'demo:reset')) {
            return $event;
        }
    }

    return null;
}

/** Seed the smoke persona and nothing else — the sales personas cost minutes. */
function demoCronSeedSmoke(array $options = []): void
{
    test()->artisan('demo:seed', ['--tenant' => (new SmokeDemoPersona)->slug()] + $options)
        ->assertSuccessful()
        ->run();
}

function demoCronTenant(): ?Tenant
{
    return Tenant::withoutGlobalScopes()
        ->withTrashed()
        ->where('slug', (new SmokeDemoPersona)->slug())
        ->first();
}

function demoCronBookingCount(Tenant $tenant): int
{
    return Booking::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count();
}

// --- The schedule ----------------------------------------------------------

it('schedules the nightly rebuild at the hour every persona is written for', function () {
    $event = demoCronEvent();

    expect($event)->not->toBeNull('nothing schedules demo:reset');

    // ⚠️ Not a style assertion. Each persona seeder places its "recently
    // happened" data so that it is still in the past — and its live holds still
    // live — AT 03:00 Europe/Budapest (docs/20 §2.3). Two bugs have already been
    // caused by that boundary (#148, #149). If this line ever moves, four
    // seeders quietly start lying on some days and not others, so the hour is
    // pinned here, next to the reason.
    expect($event->getExpression())->toBe('0 3 * * *')
        ->and($event->timezone)->toBe('Europe/Budapest');

    // The rebuild takes minutes and grows with each persona, so a run that
    // overran its window must never have a second one started on top of it —
    // the second would purge what the first is still building.
    expect($event->withoutOverlapping)->toBeTrue();
});

it('stays a no-op on a host that has no demo tenant', function () {
    // CI, a developer's machine, and a production install before the demo is
    // seeded all look like this. The filter is what makes the entry safe to ship
    // everywhere rather than something guarded by an environment check.
    expect(Tenant::withoutGlobalScopes()->withTrashed()->demo()->exists())->toBeFalse();

    expect(demoCronEvent()?->filtersPass($this->app))->toBeFalse();
});

it('runs once a demo tenant is actually there', function () {
    demoCronSeedSmoke();

    expect(demoCronEvent()?->filtersPass($this->app))->toBeTrue();
});

it('still runs for a demo tenant that has been archived', function () {
    demoCronSeedSmoke();
    demoCronTenant()?->delete();

    // A soft-deleted demo tenant is still a demo tenant, and still something the
    // rebuild has to clear out — `existingDemoSlugs()` looks through the trash
    // for that reason. Without this the archived rows would sit there for good,
    // holding the slug the next seed wants.
    expect(demoCronEvent()?->filtersPass($this->app))->toBeTrue();
});

// --- What it undoes --------------------------------------------------------

it('wipes what a visitor did to the demo', function () {
    demoCronSeedSmoke();

    $tenant = demoCronTenant();
    $seeded = demoCronBookingCount($tenant);

    expect($seeded)->toBeGreaterThan(0);

    // A visitor books something, which is the whole point of a writable demo.
    Booking::factory()
        ->forTenant($tenant)
        ->for(Service::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->firstOrFail())
        ->create();

    expect(demoCronBookingCount($tenant))->toBe($seeded + 1);

    // ...and the next rebuild takes it away again. `demo:reset` is exactly this
    // call widened to every persona (see DemoReset::handle), so scoping it to
    // the smoke tenant tests the same path without paying for four businesses.
    demoCronSeedSmoke(['--fresh' => true]);

    $rebuilt = demoCronTenant();

    expect($rebuilt->getKey())->not->toBe($tenant->getKey())
        ->and(demoCronBookingCount($rebuilt))->toBe($seeded);
});

// --- What it says when it breaks -------------------------------------------

it('alerts when the nightly rebuild fails, instead of failing quietly', function () {
    // The AC's "deliberately broken seed", triggered for real rather than with a
    // mock — both DemoSeeder and PurgeDemoTenant are final, and this is the
    // better test anyway: it exercises the path a real failure takes.
    //
    // A real tenant holding a demo slug is the guardrail that stops the rebuild
    // dead (docs/20 §3.1). It is also cheap to provoke: the smoke persona is
    // first in DemoSeeder::PERSONAS, so the run refuses before building anything.
    Tenant::factory()->active()->create([
        'slug' => (new SmokeDemoPersona)->slug(),
        'is_demo' => false,
    ]);

    Log::spy();

    $this->artisan('demo:reset')->assertFailed();

    // ⚠️ The point of the whole issue. Without this the demo would simply stop
    // being rebuilt one night and nobody would find out until a prospect did:
    // a half-built demo does not look broken, it looks like a product with
    // missing features.
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'demo reset failed')
            && array_key_exists('tenants', $context)
            && is_float($context['seconds']))
        ->once();
});

it('reports a rebuild that refused, not just one that crashed', function () {
    // Two shapes of failure reach demo:reset differently, and a handler that
    // only caught throws would miss this one: `demo:seed` converts its guardrail
    // refusals into a non-zero exit code without re-raising. The alert above
    // proves the exit-code path is covered; this pins the exit code itself, so
    // the cron mailer has something to send too.
    Tenant::factory()->active()->create([
        'slug' => (new SmokeDemoPersona)->slug(),
        'is_demo' => false,
    ]);

    $this->artisan('demo:reset')->assertExitCode(1);

    // And the real tenant is untouched — the guardrail's actual job.
    expect(Tenant::withoutGlobalScopes()->where('slug', (new SmokeDemoPersona)->slug())->sole()->is_demo)
        ->toBeFalse();
});
