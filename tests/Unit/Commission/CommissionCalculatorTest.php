<?php

declare(strict_types=1);

use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionItem;
use App\Services\Commission\CommissionResult;

/*
 * Covers docs/10-arazasi-modell-jutalek.md §12 edge-case matrix, points 1–9
 * (the pure CommissionCalculator, IO-free). Default parameters mirror §2.1:
 *   F (free threshold) = 1_000_000 minor (10 000 Ft)
 *   K (monthly cap)    = 5_000_000 minor (50 000 Ft)
 *   base rate          = 100 bps (1.0%), integration rate = 150 bps (1.5%)
 */

const F_DEFAULT = 1_000_000;
const K_DEFAULT = 5_000_000;
const RATE_BASE = 100;
const RATE_INTEGRATION = 150;

/**
 * @param  list<array{0:int,1:int}>  $items  [amountMinor, rateBps] pairs in chronological order
 */
function calc(array $items, int $threshold = F_DEFAULT, ?int $cap = K_DEFAULT): CommissionResult
{
    $mapped = array_map(fn (array $pair) => new CommissionItem($pair[0], $pair[1]), $items);

    return (new CommissionCalculator)->calculate($mapped, $threshold, $cap);
}

// §12/1 — turnover below the threshold yields no commission.
it('charges nothing below the free threshold', function () {
    $result = calc([[800_000, RATE_BASE]]);

    expect($result->commissionMinor)->toBe(0)
        ->and($result->billableBaseMinor)->toBe(0)
        ->and($result->turnoverMinor)->toBe(800_000)
        ->and($result->capReached)->toBeFalse();
});

// §12/2 — exactly at the threshold is still free.
it('charges nothing exactly at the threshold', function () {
    $result = calc([[F_DEFAULT, RATE_BASE]]);

    expect($result->commissionMinor)->toBe(0)
        ->and($result->billableBaseMinor)->toBe(0)
        ->and($result->turnoverMinor)->toBe(F_DEFAULT);
});

// §12/3 — crossing the threshold charges only the marginal part above it.
it('charges only the marginal part above the threshold', function () {
    // 30 000 Ft turnover -> 20 000 Ft above threshold -> 1% = 200 Ft.
    $result = calc([[3_000_000, RATE_BASE]]);

    expect($result->billableBaseMinor)->toBe(2_000_000)
        ->and($result->commissionMinor)->toBe(20_000)
        ->and($result->turnoverMinor)->toBe(3_000_000)
        ->and($result->capReached)->toBeFalse();
});

// §12/4 — several bookings that together cross the threshold are billed on the combined excess.
it('fills the threshold across multiple bookings', function () {
    $result = calc([
        [400_000, RATE_BASE],
        [400_000, RATE_BASE],
        [400_000, RATE_BASE], // cumulative 1 200 000 -> 200 000 above threshold
    ]);

    expect($result->turnoverMinor)->toBe(1_200_000)
        ->and($result->billableBaseMinor)->toBe(200_000)
        ->and($result->commissionMinor)->toBe(2_000); // 1% of 200 000
});

// §12/5 — when the cap is hit exactly, the period commission stops growing.
it('stops exactly at the cap', function () {
    // base 500 000 000 @ 1% = 5 000 000 == K
    $result = calc([[F_DEFAULT + 500_000_000, RATE_BASE]]);

    expect($result->commissionMinor)->toBe(K_DEFAULT)
        ->and($result->capReached)->toBeTrue();
});

// §12/6 — turnover beyond the cap is clamped to the cap.
it('clamps commission to the cap', function () {
    // 6 000 000 Ft turnover -> raw 59 900 Ft -> clamped to 50 000 Ft.
    $result = calc([[600_000_000, RATE_BASE]]);

    expect($result->commissionMinor)->toBe(K_DEFAULT)
        ->and($result->capReached)->toBeTrue()
        ->and($result->billableBaseMinor)->toBe(599_000_000);
});

// §12/7 — F = 0 and K = null: charged from the first minor, unbounded.
it('charges from the first minor when threshold is zero and cap is null', function () {
    $result = calc([[10_000_000_000, RATE_BASE]], threshold: 0, cap: null);

    expect($result->billableBaseMinor)->toBe(10_000_000_000)
        ->and($result->commissionMinor)->toBe(100_000_000) // 1% of 10 000 000 000
        ->and($result->capReached)->toBeFalse();
});

// §12/8 — rounding is deterministic floor (no fractional minor units).
it('floors commission deterministically', function () {
    // 333 * 100 / 10000 = 3.33 -> 3
    expect(calc([[333, RATE_BASE]], threshold: 0, cap: null)->commissionMinor)->toBe(3);
    // 12345 * 150 / 10000 = 185.175 -> 185
    expect(calc([[12_345, RATE_INTEGRATION]], threshold: 0, cap: null)->commissionMinor)->toBe(185);
});

// §12/9 — mixed rates in chronological order: the threshold is filled by earlier
// turnover, so the higher rate only loads onto later, above-threshold turnover.
it('applies mixed rates by chronological order, never retroactively', function () {
    // 800 000 @ 1% (below threshold) then 600 000 @ 1.5%: only 400 000 is billable, all at 1.5%.
    $higherRateLast = calc([
        [800_000, RATE_BASE],
        [600_000, RATE_INTEGRATION],
    ]);

    expect($higherRateLast->billableBaseMinor)->toBe(400_000)
        ->and($higherRateLast->commissionMinor)->toBe(6_000); // 1.5% of 400 000

    // Swap the rates: the earlier item still only fills the threshold (its rate is
    // irrelevant below the line); the later item's 400 000 excess is billed at ITS rate.
    $higherRateFirst = calc([
        [800_000, RATE_INTEGRATION],
        [600_000, RATE_BASE],
    ]);

    expect($higherRateFirst->billableBaseMinor)->toBe(400_000)
        ->and($higherRateFirst->commissionMinor)->toBe(4_000); // 1% of 400 000
});

// §2.3 worked-example table (default parameters, uniform 1% rate).
it('matches the worked example table from §2.3', function (int $turnoverMinor, int $expectedCommission) {
    expect(calc([[$turnoverMinor, RATE_BASE]])->commissionMinor)->toBe($expectedCommission);
})->with([
    'below threshold (8 000 Ft)' => [800_000, 0],
    'at threshold (10 000 Ft)' => [1_000_000, 0],
    'above threshold (30 000 Ft)' => [3_000_000, 20_000],
    'mid (1 000 000 Ft)' => [100_000_000, 990_000],
    'capped (6 000 000 Ft)' => [600_000_000, 5_000_000],
]);

// Empty period is a valid, zeroed result.
it('returns a zeroed result for an empty period', function () {
    $result = calc([]);

    expect($result->turnoverMinor)->toBe(0)
        ->and($result->billableBaseMinor)->toBe(0)
        ->and($result->commissionMinor)->toBe(0)
        ->and($result->capReached)->toBeFalse();
});
