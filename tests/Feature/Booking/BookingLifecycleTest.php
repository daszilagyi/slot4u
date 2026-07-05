<?php

use App\Actions\Booking\CancelBooking;
use App\Enums\BookingStatus;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    Carbon::setTestNow();
});

it('generates a unique non-guessable code and initial history on creation', function () {
    Event::fake([BookingCreated::class]);
    $tenant = Tenant::factory()->active()->create();

    $booking = Booking::factory()->forTenant($tenant)->create();

    expect($booking->code)->toHaveLength(8)
        ->and($booking->code)->toMatch('/^[A-Z0-9]{8}$/');

    // The initial status is logged as a null → status history entry.
    $initial = $booking->statusHistory()->sole();
    expect($initial->from_status)->toBeNull()
        ->and($initial->to_status)->toBe($booking->status);

    Event::assertDispatched(BookingCreated::class);
});

it('gives two bookings distinct codes', function () {
    $tenant = Tenant::factory()->active()->create();
    $a = Booking::factory()->forTenant($tenant)->create();
    $b = Booking::factory()->forTenant($tenant)->create();

    expect($a->code)->not->toBe($b->code);
});

it('refuses an online cancellation inside the tenant cancellation deadline', function () {
    Carbon::setTestNow('2026-09-01 00:00:00');
    $tenant = Tenant::factory()->active()->create(['settings' => ['cancellation_deadline_hours' => 24]]);
    // Starts in 10h → inside the 24h deadline.
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-01 10:00:00', '2026-09-01 11:00:00')->create();

    expect(fn () => app(CancelBooking::class)($booking, null, null, online: true))
        ->toThrow(ValidationException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('allows an online cancellation outside the deadline', function () {
    Carbon::setTestNow('2026-09-01 00:00:00');
    $tenant = Tenant::factory()->active()->create(['settings' => ['cancellation_deadline_hours' => 24]]);
    // Starts in >48h → outside the 24h deadline.
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-03 10:00:00', '2026-09-03 11:00:00')->create();

    app(CancelBooking::class)($booking, null, null, online: true);

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

it('treats the exact cancellation boundary as inside the deadline', function () {
    Carbon::setTestNow('2026-09-01 00:00:00');
    $tenant = Tenant::factory()->active()->create(['settings' => ['cancellation_deadline_hours' => 24]]);
    // Starts exactly 24h from now → the deadline threshold equals "now" (gte).
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-02 00:00:00', '2026-09-02 01:00:00')->create();

    expect(fn () => app(CancelBooking::class)($booking, null, null, online: true))
        ->toThrow(ValidationException::class);
});

it('allows an online cancellation of a timeless booking', function () {
    $tenant = Tenant::factory()->active()->create(['settings' => ['cancellation_deadline_hours' => 24]]);
    // no_time_slot mode: no start time → never inside a cancellation window.
    $booking = Booking::factory()->forTenant($tenant)->create(['starts_at' => null, 'ends_at' => null]);

    app(CancelBooking::class)($booking, null, null, online: true);

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled);
});

it('always allows an admin cancellation even inside the deadline', function () {
    Carbon::setTestNow('2026-09-01 00:00:00');
    $tenant = Tenant::factory()->active()->create(['settings' => ['cancellation_deadline_hours' => 24]]);
    $booking = Booking::factory()->forTenant($tenant)
        ->startingAt('2026-09-01 10:00:00', '2026-09-01 11:00:00')->create();

    // online: false = admin acting; the deadline does not apply.
    app(CancelBooking::class)($booking, null, 'admin override', online: false);

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($booking->cancel_reason)->toBe('admin override');
});

it('scopes bookings to their tenant', function () {
    $tenant = Tenant::factory()->active()->create();
    $other = Tenant::factory()->active()->create();
    Booking::factory()->forTenant($tenant)->create();
    Booking::factory()->forTenant($other)->create();

    app(TenantManager::class)->set($tenant);
    expect(Booking::count())->toBe(1);
    app(TenantManager::class)->forget();
});
