<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Closure;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The shared toolkit every demo persona builds with (SLO-183, docs/20 §1, §3.2–3.3).
 *
 * Three problems the personas would otherwise each solve differently, and each
 * one is load-bearing for what the demo is FOR:
 *
 * 1. **Determinism** (§1.4). One seeded generator per persona, so two runs
 *    produce byte-identical data. That is what lets a screenshot taken today
 *    still match the demo next month, and what lets a test assert on a name.
 * 2. **Relative dates** (§1.3). Every instant is expressed against
 *    {@see self::today()}, never a literal. A demo with hard-coded dates looks
 *    abandoned within a fortnight — the exact impression the sales demo exists
 *    to prevent.
 * 3. **Backdating** (§3.3). Past history has to be written *as if* it happened
 *    then, through the real Actions, with a consistent `booking_status_history`
 *    chain behind it.
 */
final class DemoDataFactory
{
    /**
     * Hungarian faker: the personas are Hungarian businesses and the spec asks
     * for names an owner would recognise as their own customers (§1.2), not
     * "John Doe".
     */
    private const LOCALE = 'hu_HU';

    private readonly Generator $faker;

    private readonly Carbon $today;

    /**
     * @param  string  $personaKey  seeds the generator — a stable string, so the
     *                              persona's data does not shift when personas
     *                              are added, removed or reordered around it.
     * @param  string  $timezone  the tenant's own zone: the working day is a
     *                            local notion, storage is UTC (docs/01 §7).
     */
    public function __construct(
        private readonly string $personaKey,
        private readonly string $timezone = 'Europe/Budapest',
    ) {
        $this->faker = FakerFactory::create(self::LOCALE);
        $this->faker->seed($this->seedFor($personaKey));

        // Pinned once at construction. Reading "today" repeatedly would let a
        // seed that starts at 23:59:58 place its first rows on one day and its
        // last on the next — a rare failure that would only ever be seen by
        // whoever ran the nightly reset.
        $this->today = Carbon::today($this->timezone);
    }

    /** The persona's own deterministic generator. */
    public function faker(): Generator
    {
        return $this->faker;
    }

    /**
     * Midnight today in the tenant's timezone — the base every date is relative
     * to. Handed out as a copy: Carbon is mutable here, and a persona calling
     * `->addDays()` on the shared instance would move everything seeded after it.
     */
    public function today(): Carbon
    {
        return $this->today->copy();
    }

    /**
     * A tenant-local instant on a day offset from today, as a UTC-stored Carbon.
     *
     * `$time` is wall-clock in the tenant's zone ("09:30"), which is how a
     * working day is actually expressed — and the only way a seeded booking
     * lands on the schedule grid rather than 30 minutes off it twice a year,
     * when the offset changes (docs/01 §7).
     */
    public function at(int $daysFromToday, string $time): Carbon
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time, 2)), 2, 0);

        return $this->today
            ->copy()
            ->addDays($daysFromToday)
            ->setTime($hour, $minute)
            ->utc();
    }

    /**
     * Run `$work` with the application clock moved to `$instant` (§3.3).
     *
     * This is the backdating helper, and it is deliberately a time machine
     * rather than a set of timestamp fixups. Writing a past booking by creating
     * it now and then rewriting `created_at` leaves everything else behind:
     * the `booking_status_history` rows, `approved_at`, `canceled_at`, the
     * commission item's `realized_at`. Each would need its own patch, and the
     * next column added to the lifecycle would silently not get one.
     *
     * Moving the clock instead means the real Actions write consistent history
     * *by construction* — the seed exercises the same code path a live booking
     * does, which is also what makes the demo data a genuine smoke test of the
     * booking engine (docs/20 §5.8).
     *
     * ⚠️ `finally`: an exception escaping with the clock still frozen would
     * leave every later query — and, in the test suite, every later test —
     * living in the past. That failure is far harder to read than the one that
     * caused it.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws Throwable
     */
    public function asOf(Carbon $instant, Closure $work): mixed
    {
        $previous = Carbon::hasTestNow() ? Carbon::getTestNow() : null;

        Carbon::setTestNow($instant);

        try {
            return $work();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * One of `$values`, chosen deterministically. Thin, but it keeps the
     * personas from reaching for `array_rand()`/`shuffle()`, which read from
     * PHP's global generator and would quietly break determinism.
     *
     * @template T
     *
     * @param  non-empty-list<T>  $values
     * @return T
     */
    public function oneOf(array $values): mixed
    {
        return $values[$this->faker->numberBetween(0, count($values) - 1)];
    }

    /** A deterministic integer in `[$min, $max]`. */
    public function between(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    /**
     * A stable 32-bit seed from the persona key. `crc32` rather than a random
     * constant per persona: the key already identifies the persona, so there is
     * no second thing to keep in sync.
     */
    private function seedFor(string $personaKey): int
    {
        return (int) sprintf('%u', crc32($personaKey));
    }

    public function personaKey(): string
    {
        return $this->personaKey;
    }

    public function timezone(): string
    {
        return $this->timezone;
    }
}
