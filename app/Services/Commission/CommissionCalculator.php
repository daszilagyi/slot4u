<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * Pure, IO-free implementation of the turnover-based commission model.
 *
 * Given the resolved parameters (free threshold F, monthly cap K) and the
 * period's billable bookings in chronological order, it computes the marginal
 * commission with a monthly cap, exactly as specified in
 * docs/10-arazasi-modell-jutalek.md §2.3.
 *
 * Properties (all integer arithmetic, float forbidden — docs/01 §6):
 *  - Below the threshold the commission is 0.
 *  - Only turnover above the threshold is billable (marginal).
 *  - The threshold is filled by earlier turnover in chronological order, so a
 *    mid-month rate change (§2.4) only loads the higher rate onto later,
 *    above-threshold turnover — never retroactively.
 *  - Rounding is always floor (in the tenant's favour), deterministic.
 *  - The cap clamps the period total; F = 0 and K = null are valid edge cases.
 *
 * Negative correction items (credits, §8.2/§15.5) are out of scope here and are
 * handled at the ledger/invoice layer (J6); this service mirrors the §2.3
 * pseudocode for billable bookings.
 */
final class CommissionCalculator
{
    private const int BPS_DIVISOR = 10_000;

    /**
     * @param  list<CommissionItem>  $items  Billable bookings, ordered by the time they became billable.
     * @param  int  $freeThresholdMinor  F — monthly free turnover threshold (minor units).
     * @param  int|null  $monthlyCapMinor  K — monthly commission cap (minor units); null = uncapped.
     */
    public function calculate(array $items, int $freeThresholdMinor, ?int $monthlyCapMinor): CommissionResult
    {
        $threshold = $freeThresholdMinor;

        $cumulativeTurnover = 0;
        $billableBase = 0;
        $rawCommission = 0;

        foreach ($items as $item) {
            $amount = $item->amountMinor;

            // The above-threshold, billable slice contributed by THIS item only.
            $base = max(0, ($cumulativeTurnover + $amount) - max($threshold, $cumulativeTurnover));

            $billableBase += $base;
            // Integer division floors towards zero (amounts and rates are non-negative).
            $rawCommission += intdiv($base * $item->rateBps, self::BPS_DIVISOR);
            $cumulativeTurnover += $amount;
        }

        $capReached = $monthlyCapMinor !== null && $rawCommission >= $monthlyCapMinor;
        $commission = $monthlyCapMinor !== null
            ? min($rawCommission, $monthlyCapMinor)
            : $rawCommission;

        return new CommissionResult(
            turnoverMinor: $cumulativeTurnover,
            billableBaseMinor: $billableBase,
            commissionMinor: $commission,
            capReached: $capReached,
        );
    }
}
