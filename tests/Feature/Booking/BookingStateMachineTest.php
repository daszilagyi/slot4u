<?php

use App\Actions\Booking\ChangeBookingStatus;
use App\Enums\BookingStatus;
use App\Events\BookingCanceled;
use App\Events\BookingStatusChanged;
use App\Exceptions\InvalidBookingTransitionException;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Event;

/** A booking in the given tenant + status (its initial history row is written). */
function bookingWith(BookingStatus $status): Booking
{
    $tenant = Tenant::factory()->active()->create();

    return Booking::factory()->forTenant($tenant)->status($status)->create();
}

it('enumerates the allowed transitions from the state graph', function () {
    expect(BookingStatus::Requested->allowedTransitions())
        ->toBe([BookingStatus::Approved, BookingStatus::Rejected, BookingStatus::Canceled])
        ->and(BookingStatus::Confirmed->allowedTransitions())
        ->toBe([BookingStatus::Completed, BookingStatus::Canceled, BookingStatus::NoShow])
        ->and(BookingStatus::Completed->isTerminal())->toBeTrue()
        ->and(BookingStatus::Canceled->isTerminal())->toBeTrue()
        ->and(BookingStatus::Confirmed->isTerminal())->toBeFalse();
});

it('performs a valid transition and records history with the actor', function () {
    Event::fake([BookingStatusChanged::class, BookingCanceled::class]);
    $booking = bookingWith(BookingStatus::Requested);
    $actor = User::factory()->create();

    app(ChangeBookingStatus::class)($booking, BookingStatus::Approved, $actor);

    expect($booking->fresh()->status)->toBe(BookingStatus::Approved)
        ->and($booking->approved_by)->toBe($actor->id)
        ->and($booking->approved_at)->not->toBeNull();

    // Initial (null → requested) + the approve transition.
    $history = $booking->statusHistory()->orderBy('id')->get();
    expect($history)->toHaveCount(2)
        ->and($history->last()->from_status)->toBe(BookingStatus::Requested)
        ->and($history->last()->to_status)->toBe(BookingStatus::Approved)
        ->and($history->last()->actor_id)->toBe($actor->id);

    Event::assertDispatched(BookingStatusChanged::class);
});

it('rejects an invalid transition and leaves the booking unchanged', function () {
    $booking = bookingWith(BookingStatus::Confirmed);

    expect(fn () => app(ChangeBookingStatus::class)($booking, BookingStatus::Requested))
        ->toThrow(InvalidBookingTransitionException::class);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->statusHistory()->count())->toBe(1); // only the initial entry
});

it('rejects any transition out of a terminal status', function () {
    $booking = bookingWith(BookingStatus::Completed);

    expect(fn () => app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled))
        ->toThrow(InvalidBookingTransitionException::class);
});

it('dispatches BookingCanceled on cancellation', function () {
    Event::fake([BookingStatusChanged::class, BookingCanceled::class]);
    $booking = bookingWith(BookingStatus::Confirmed);

    app(ChangeBookingStatus::class)($booking, BookingStatus::Canceled, null, 'customer request');

    expect($booking->fresh()->status)->toBe(BookingStatus::Canceled)
        ->and($booking->canceled_at)->not->toBeNull()
        ->and($booking->cancel_reason)->toBe('customer request');

    Event::assertDispatched(BookingCanceled::class);
});

// Every valid edge in the docs/04 state graph must be allowed.
it('allows each valid transition', function (BookingStatus $from, BookingStatus $to) {
    $booking = bookingWith($from);

    app(ChangeBookingStatus::class)($booking, $to);

    expect($booking->fresh()->status)->toBe($to);
})->with([
    [BookingStatus::Requested, BookingStatus::Approved],
    [BookingStatus::Requested, BookingStatus::Rejected],
    [BookingStatus::Requested, BookingStatus::Canceled],
    [BookingStatus::Approved, BookingStatus::PendingPayment],
    [BookingStatus::Approved, BookingStatus::Confirmed],
    [BookingStatus::Approved, BookingStatus::Canceled],
    [BookingStatus::PendingPayment, BookingStatus::Confirmed],
    [BookingStatus::PendingPayment, BookingStatus::Canceled],
    [BookingStatus::Confirmed, BookingStatus::Completed],
    [BookingStatus::Confirmed, BookingStatus::Canceled],
    [BookingStatus::Confirmed, BookingStatus::NoShow],
]);

// A representative set of illegal edges must be refused.
it('forbids each invalid transition', function (BookingStatus $from, BookingStatus $to) {
    $booking = bookingWith($from);

    expect(fn () => app(ChangeBookingStatus::class)($booking, $to))
        ->toThrow(InvalidBookingTransitionException::class);
})->with([
    [BookingStatus::Requested, BookingStatus::Confirmed],
    [BookingStatus::Requested, BookingStatus::Completed],
    [BookingStatus::Confirmed, BookingStatus::Requested],
    [BookingStatus::Confirmed, BookingStatus::Approved],
    [BookingStatus::PendingPayment, BookingStatus::Completed],
    [BookingStatus::Completed, BookingStatus::Canceled],
    [BookingStatus::Canceled, BookingStatus::Confirmed],
    [BookingStatus::Rejected, BookingStatus::Approved],
    [BookingStatus::NoShow, BookingStatus::Confirmed],
    // A self-transition is not a valid edge.
    [BookingStatus::Confirmed, BookingStatus::Confirmed],
]);
