<?php

declare(strict_types=1);

namespace App\Services\Platform;

/**
 * One turnover band of the platform's tenant distribution for a billing period
 * (SLO-138). The bands replace the "package distribution" of the original
 * SLO-45 description: the three-tier packaging is gone (docs/10), so what a
 * tenant is worth to the platform is its turnover, not its plan.
 *
 * Bounds are anchored on the commission free threshold (docs/10 §2.3) rather
 * than round numbers, so the bands keep meaning their business story — "not yet
 * paying", "just over the line", "carrying the platform" — when the pricing
 * model is re-versioned.
 */
final readonly class TurnoverBandStat
{
    public function __construct(
        /** i18n key suffix: `up_to_1x` | `up_to_2x` | `up_to_5x` | `above_5x`. */
        public string $key,
        /** Exclusive lower bound in minor units. */
        public int $fromMinor,
        /** Inclusive upper bound in minor units; null = unbounded. */
        public ?int $toMinor,
        public int $tenants,
        public int $turnoverMinor,
        /** What the band is actually billed for, net of credits (docs/10 §8.2). */
        public int $commissionMinor,
    ) {}
}
