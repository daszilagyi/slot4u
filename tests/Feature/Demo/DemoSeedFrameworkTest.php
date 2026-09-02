<?php

use App\Enums\BookingStatus;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Demo\PurgeDemoTenant;
use Database\Seeders\Demo\DemoSeeder;
use Database\Seeders\Demo\SmokeDemoPersona;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Demo seed framework (SLO-183, docs/20 §3.2–3.3)
|--------------------------------------------------------------------------
|
| The framework the four sales personas will be built on. What is tested here
| is not the content — that is SLO-184..190 — but the four properties the
| personas inherit and cannot each get right on their own:
|
|   determinism, idempotency, backdated-but-consistent history, and the
|   guardrails that keep a destructive command away from real data.
|
| The last one carries a trap worth naming: `users.tenant_id` is `nullOnDelete`
| and `tenant_id === null` IS a platform super-admin in this codebase, so the
| obvious teardown — delete the tenant, let the cascade sort it out — promotes
| every demo account instead of removing it. There is a test for exactly that.
|
*/

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
});

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    Carbon::setTestNow();
});

/** The smoke persona's tenant, however many scopes are in the way. */
function demoFrameworkTenant(?string $slug = null): ?Tenant
{
    return Tenant::withoutGlobalScopes()
        ->withTrashed()
        ->where('slug', $slug ?? (new SmokeDemoPersona)->slug())
        ->first();
}

/** Every booking of a tenant, oldest first. */
function demoFrameworkBookings(Tenant $tenant)
{
    return Booking::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->orderBy('starts_at')
        ->get();
}

/** A content fingerprint that ignores ids and timestamps — what determinism means here. */
function demoFrameworkFingerprint(Tenant $tenant): string
{
    return md5(demoFrameworkBookings($tenant)
        ->map(fn (Booking $b): string => implode('|', [
            $b->starts_at->toIso8601String(),
            (string) $b->guest_name,
            (string) $b->guest_email,
            (string) $b->price_minor,
            $b->status->value,
        ]))
        ->join("\n"));
}

// --- It builds something ---------------------------------------------------

it('seeds a demo tenant that is flagged, active and has an admin', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $tenant = demoFrameworkTenant();

    expect($tenant)->not->toBeNull()
        // Flagged, so every SLO-182 guardrail applies to it.
        ->and($tenant->is_demo)->toBeTrue()
        // Active rather than trial: a demo that expires is a demo that is broken
        // on the morning nobody checked.
        ->and($tenant->status)->toBe(TenantStatus::Active);

    $admin = User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->sole();

    expect($admin->email)->toBe((new SmokeDemoPersona)->adminEmail())
        // A non-deliverable domain by design (docs/20 §2).
        ->and($admin->email)->toEndWith('.demo.slot4u.hu');

    // The persona actually built a business, not just a tenant row.
    expect(Location::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())->toBe(1)
        ->and(Service::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->count())->toBe(1)
        ->and(demoFrameworkBookings($tenant))->not->toBeEmpty();
});

it('serves the seeded tenant on its own subdomain', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    // docs/20 acceptance: the demo has to be reachable where the sales link
    // points, not merely present in the database.
    $this->get(tenantHost((new SmokeDemoPersona)->slug(), '/'))->assertOk();
});

// --- Relative dates and backdated history ----------------------------------

it('leaves history behind it and a bookable calendar ahead of it', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $bookings = demoFrameworkBookings(demoFrameworkTenant());
    $past = $bookings->filter(fn (Booking $b): bool => $b->starts_at->isPast());
    $future = $bookings->filter(fn (Booking $b): bool => $b->starts_at->isFuture());

    // Both halves, always: a demo with no history looks brand new, and one with
    // no future looks abandoned (docs/20 §1.3).
    expect($past)->not->toBeEmpty()
        ->and($future)->not->toBeEmpty()
        // A past appointment still sitting at "confirmed" is what a dead system
        // looks like.
        ->and($past->every(fn (Booking $b): bool => $b->status === BookingStatus::Completed))->toBeTrue();
});

it('writes a past booking as if it had happened then', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $booking = demoFrameworkBookings(demoFrameworkTenant())
        ->first(fn (Booking $b): bool => $b->starts_at->isPast());

    // The backdating helper's whole job: not "created_at was patched", but that
    // the row is coherent — booked BEFORE the appointment it is for.
    expect($booking->created_at->lt($booking->starts_at))->toBeTrue();

    $history = BookingStatusHistory::withoutGlobalScopes()
        ->where('booking_id', $booking->getKey())
        ->orderBy('id')
        ->get();

    // Written by the real state machine, so the chain exists at all...
    expect($history)->not->toBeEmpty()
        ->and($history->last()->to_status)->toBe(BookingStatus::Completed)
        // ...and every entry sits in the past alongside the booking, rather than
        // at the instant the seeder ran. This is what a `created_at` fixup alone
        // would have missed.
        ->and($history->every(fn (BookingStatusHistory $h): bool => $h->created_at->isPast()))->toBeTrue()
        ->and($history->first()->created_at->lt($booking->starts_at))->toBeTrue();
});

// --- Determinism and idempotency -------------------------------------------

it('produces identical data on every rebuild', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $first = demoFrameworkFingerprint(demoFrameworkTenant());

    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $second = demoFrameworkFingerprint(demoFrameworkTenant());

    // Screenshots, docs and the personas' own tests all rest on this (§1.4).
    expect($second)->toBe($first)->and($first)->not->toBeEmpty();
});

it('leaves an existing demo tenant untouched without --fresh', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $before = demoFrameworkTenant();
    $bookingIds = demoFrameworkBookings($before)->modelKeys();

    $this->artisan('demo:seed')
        ->expectsOutputToContain('Nothing to seed')
        ->assertSuccessful();

    $after = demoFrameworkTenant();

    // The same rows, not merely the same data: re-running mid-demo must not pull
    // the ground out from under whoever is presenting (§3.2).
    expect($after->getKey())->toBe($before->getKey())
        ->and(demoFrameworkBookings($after)->modelKeys())->toBe($bookingIds);
});

it('rebuilds from nothing with --fresh', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $firstId = demoFrameworkTenant()->getKey();

    $this->artisan('demo:seed', ['--fresh' => true])->assertSuccessful();
    $tenant = demoFrameworkTenant();

    // A new tenant row — the old one was deleted, not updated in place...
    expect($tenant->getKey())->not->toBe($firstId)
        // ...and nothing of the old one survived the cascade.
        ->and(Booking::withoutGlobalScopes()->where('tenant_id', $firstId)->count())->toBe(0);
});

it('rebuilds every demo tenant with demo:reset', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $firstId = demoFrameworkTenant()->getKey();

    $this->artisan('demo:reset')->assertSuccessful();

    expect(demoFrameworkTenant()->getKey())->not->toBe($firstId);
});

// --- Guardrails ------------------------------------------------------------

it('⚠️ never leaves a demo account behind as a platform super-admin', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $tenant = demoFrameworkTenant();
    $demoEmails = User::withoutGlobalScopes()
        ->where('tenant_id', $tenant->getKey())
        ->pluck('email');

    expect($demoEmails)->not->toBeEmpty();

    app(PurgeDemoTenant::class)($tenant);

    // ⚠️ THE trap. `users.tenant_id` is nullOnDelete and `tenant_id === null` is
    // the definition of a super-admin (User::isSuperAdmin), so deleting the
    // tenant and letting the database cascade would PROMOTE these accounts —
    // silently, nightly, with a password published in docs/20.
    $survivors = User::withoutGlobalScopes()->whereIn('email', $demoEmails)->get();

    expect($survivors)->toBeEmpty();

    // Stated the other way round too, so the assertion still bites if the demo
    // emails ever change shape.
    expect(User::withoutGlobalScopes()->whereNull('tenant_id')->pluck('email'))
        ->not->toContain($demoEmails->first());
});

it('refuses to seed over a tenant that is not a demo one', function () {
    // A real customer that happens to own the slug.
    $real = Tenant::factory()->active()->create([
        'slug' => (new SmokeDemoPersona)->slug(),
        'name' => 'Igazi Ügyfél Kft.',
    ]);

    $this->artisan('demo:seed')->assertFailed();

    $survivor = demoFrameworkTenant();

    // Untouched: same row, same name, still not a demo tenant. This is the
    // irreversible failure the guard exists for.
    expect($survivor->getKey())->toBe($real->getKey())
        ->and($survivor->name)->toBe('Igazi Ügyfél Kft.')
        ->and($survivor->is_demo)->toBeFalse();
});

it('refuses to purge a tenant that is not a demo one', function () {
    $real = Tenant::factory()->active()->create();

    // The command checks as well; this pins the service itself, because it is
    // what actually does the deleting.
    expect(fn () => app(PurgeDemoTenant::class)($real))
        ->toThrow(RuntimeException::class);

    expect(Tenant::withoutGlobalScopes()->whereKey($real->getKey())->exists())->toBeTrue();
});

it('fails on an unknown persona slug instead of seeding everything', function () {
    $this->artisan('demo:seed', ['--tenant' => 'nincs-ilyen'])->assertFailed();

    expect(demoFrameworkTenant())->toBeNull();
});

it('seeds only the named persona', function () {
    $this->artisan('demo:seed', ['--tenant' => (new SmokeDemoPersona)->slug()])->assertSuccessful();

    expect(demoFrameworkTenant())->not->toBeNull();
});

it('purges the demo tenant audit trail, which no foreign key would', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $tenant = demoFrameworkTenant();

    // `audit_logs.tenant_id` carries no constraint on purpose — the trail is
    // built to outlive the tenant. For a demo tenant that is pure accumulation,
    // one dangling batch per nightly reset.
    DB::table('audit_logs')->insert([
        'tenant_id' => $tenant->getKey(),
        'action' => 'tenant.updated',
        'created_at' => now(),
    ]);

    app(PurgeDemoTenant::class)($tenant);

    expect(DB::table('audit_logs')->where('tenant_id', $tenant->getKey())->count())->toBe(0);
});

it('registers every persona exactly once', function () {
    $slugs = array_map(
        static fn (string $class): string => (new $class)->slug(),
        DemoSeeder::PERSONAS,
    );

    // A duplicated slug would have one persona silently overwrite another on
    // --fresh, and the list is hand-maintained.
    expect($slugs)->toBe(array_unique($slugs))
        ->and($slugs)->not->toBeEmpty();
});
