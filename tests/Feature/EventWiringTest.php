<?php

use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Listeners\RecordBookingCommission;
use App\Listeners\RecordNotificationDelivery;
use App\Listeners\SendBookingConfirmation;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Event wiring (SLO-174)
|--------------------------------------------------------------------------
|
| A listener registered twice is the kind of bug that leaves no mark. Every
| listener in this app happened to be idempotent — the notifier dedupes, the
| ledger upserts — so the whole booking pipeline ran twice per event for
| months and every test stayed green.
|
| These tests exist because "the suite passes" could never have caught it.
| They look at the registry itself, and at the one property that matters:
| each listener is wired exactly once, and nothing lost its wiring when
| discovery was turned off.
|
*/

/**
 * Every listener registered for `$event`, as class names.
 *
 * Normalises the three shapes the dispatcher stores: `Class`, `Class@method`
 * (what discovery writes) and `[Class, 'method']`. Comparing raw strings would
 * miss the exact duplication this file is about — `Foo` and `Foo@handle` are
 * different strings and the same listener.
 *
 * @return list<string>
 */
function listenerClassesFor(string $event): array
{
    $raw = (array) (Event::getRawListeners()[$event] ?? []);
    $classes = [];

    foreach ($raw as $listener) {
        if (is_string($listener)) {
            $classes[] = explode('@', $listener)[0];
        } elseif (is_array($listener) && is_string($listener[0] ?? null)) {
            $classes[] = $listener[0];
        }
    }

    return $classes;
}

it('wires every listener exactly once, on every event the app dispatches', function () {
    // The whole registry, not a sample: the failure mode is a listener nobody
    // was thinking about, so a hand-picked list would have missed the ones that
    // mattered.
    $duplicated = [];

    foreach (array_keys(Event::getRawListeners()) as $event) {
        if (! is_string($event)) {
            continue;
        }

        $classes = array_filter(
            listenerClassesFor($event),
            static fn (string $class): bool => str_starts_with($class, 'App\\Listeners'),
        );

        foreach (array_count_values($classes) as $class => $count) {
            if ($count > 1) {
                $duplicated[] = $event.' → '.$class.' ×'.$count;
            }
        }
    }

    expect($duplicated)->toBe([]);
});

it('still wires the listeners it is supposed to', function () {
    // The other half of turning discovery off: proving nothing went with it.
    // Every discovered listener had an explicit twin when this landed, so the
    // registry should be unchanged apart from the duplicates — but "should be"
    // is what the last six months were built on.
    expect(listenerClassesFor(BookingCreated::class))
        ->toContain(SendBookingConfirmation::class)
        ->toContain(RecordBookingCommission::class)
        ->and(listenerClassesFor(BookingStatusChanged::class))
        ->toContain(SendBookingConfirmation::class)
        ->toContain(RecordBookingCommission::class);
});

it('keeps a listener that is wired by method name rather than by handle', function () {
    // RecordNotificationDelivery is registered as [Class, 'sent'] / [Class,
    // 'failed'] and has no handle() at all, so discovery never saw it. It is the
    // one listener that could NOT have been affected — which is exactly why it
    // is worth pinning: if it ever disappears, the cause is something else.
    expect(listenerClassesFor(NotificationSent::class))
        ->toContain(RecordNotificationDelivery::class)
        ->and(listenerClassesFor(NotificationFailed::class))
        ->toContain(RecordNotificationDelivery::class);
});
