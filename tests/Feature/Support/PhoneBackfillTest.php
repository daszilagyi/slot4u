<?php

use App\Models\Booking;
use App\Models\Location;
use App\Models\QuoteRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one-off pass that rewrites the phone numbers already on disk (SLO-151).
 *
 * RefreshDatabase migrates an empty database, so the migration ran against
 * nothing. These tests plant the legacy shapes it exists for and run it again —
 * which is also the proof that a second run is harmless.
 */
function runPhoneBackfill(): void
{
    (require __DIR__.'/../../../database/migrations/2026_08_09_000001_normalize_stored_phone_numbers.php')->up();
}

it('rewrites every stored phone column to E.164', function () {
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Budapest']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $location = Location::factory()->forTenant($tenant)->create();
    $booking = Booking::factory()->forTenant($tenant)->create();
    $quote = QuoteRequest::factory()->forTenant($tenant)->create();

    // Straight to the table: the shapes predate any normalization.
    DB::table('users')->where('id', $user->id)->update(['phone' => '06 30/123-4567']);
    DB::table('locations')->where('id', $location->id)->update(['phone' => '06 1 234 5678']);
    DB::table('bookings')->where('id', $booking->id)->update(['guest_phone' => '+36 30 123 4567']);
    DB::table('quote_requests')->where('id', $quote->id)->update(['guest_phone' => '0036301234567']);
    DB::table('tenants')->where('id', $tenant->id)->update(['settings' => json_encode(['phone' => '06 20 999 9999'])]);

    runPhoneBackfill();

    expect($user->refresh()->phone)->toBe('+36301234567')
        ->and($location->refresh()->phone)->toBe('+3612345678')
        ->and($booking->refresh()->guest_phone)->toBe('+36301234567')
        ->and($quote->refresh()->guest_phone)->toBe('+36301234567')
        ->and($tenant->refresh()->settings['phone'])->toBe('+36209999999');
});

it('leaves alone what it cannot parse, rather than dropping it', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);

    DB::table('users')->where('id', $user->id)->update(['phone' => 'sfsdfsdfsd']);

    runPhoneBackfill();

    // Junk is a human's problem to fix; deleting it would destroy the only clue
    // about who the tenant was trying to reach.
    expect($user->refresh()->phone)->toBe('sfsdfsdfsd');
});

it('reads each row region from its own tenant', function () {
    $austrian = Tenant::factory()->create(['timezone' => 'Europe/Vienna']);
    $hungarian = Tenant::factory()->create(['timezone' => 'Europe/Budapest']);
    $abroad = User::factory()->create(['tenant_id' => $austrian->id]);
    $home = User::factory()->create(['tenant_id' => $hungarian->id]);

    // The same national string means different numbers in the two countries.
    DB::table('users')->where('id', $abroad->id)->update(['phone' => '0664 1234567']);
    DB::table('users')->where('id', $home->id)->update(['phone' => '06 30 123 4567']);

    runPhoneBackfill();

    expect($abroad->refresh()->phone)->toBe('+436641234567')
        ->and($home->refresh()->phone)->toBe('+36301234567');
});

it('is safe to run twice', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    DB::table('users')->where('id', $user->id)->update(['phone' => '06 30 123 4567']);

    runPhoneBackfill();
    runPhoneBackfill();

    expect($user->refresh()->phone)->toBe('+36301234567');
});

it('does not stall on a tenantless superadmin row', function () {
    $super = User::factory()->create(['tenant_id' => null]);
    DB::table('users')->where('id', $super->id)->update(['phone' => '06 30 123 4567']);

    runPhoneBackfill();

    // No tenant, so the platform default region applies.
    expect($super->refresh()->phone)->toBe('+36301234567');
});
