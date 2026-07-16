<?php

declare(strict_types=1);

namespace App\Services\Commission;

use Illuminate\Support\Carbon;

/**
 * A single period's commission standing as the tenant billing dashboard shows it
 * (docs/10 §9). Money is integer minor units, the rate integer basis points.
 *
 * Nullable fields mean "no ceiling / not applicable": a null `monthlyCapMinor`
 * is an uncapped pricing model, and when `configured` is false no commission
 * settings are effective yet, so the threshold/rate figures are unknown rather
 * than zero.
 */
final readonly class TenantBillingOverview
{
    /**
     * @param  list<string>  $integrationCodes  Active rate-raising integrations —
     *                                          why the rate is the raised one.
     */
    public function __construct(
        public string $period,
        public string $currency,
        public bool $configured,
        public int $turnoverMinor,
        public int $billableBaseMinor,
        public int $commissionMinor,
        public ?int $freeThresholdMinor,
        public ?int $freeRemainingMinor,
        public ?int $monthlyCapMinor,
        public ?int $capRemainingMinor,
        public bool $capReached,
        public bool $thresholdReached,
        public ?int $rateBps,
        /** The rate a rate-raising integration would move the tenant to (§2.4). */
        public ?int $raisedRateBps,
        public array $integrationCodes,
        public ?Carbon $recomputedAt,
    ) {}
}
