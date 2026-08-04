<?php

namespace App\Services\Report;

/**
 * The headline figures of one reporting period (SLO-137). Built for both the
 * selected range and the comparison range by the same code path in
 * {@see BuildTenantReport}, so a delta can never come from two different
 * definitions of the same number.
 *
 * Rates are basis points (integers), like every other rate in the codebase — no
 * float ever reaches the frontend.
 */
final class ReportTotals
{
    public function __construct(
        /** Every booking dated in the range, whatever its status. */
        public readonly int $bookings,
        /** Realised bookings: confirmed + completed + no_show (docs/10 §3.1). */
        public readonly int $realized,
        public readonly int $canceled,
        public readonly int $noShow,
        /** Sum of price_minor over the realised bookings. */
        public readonly int $revenueMinor,
        /** Distinct contacts behind the realised bookings (accounts + guests). */
        public readonly int $customers,
    ) {}

    /** Average realised booking value, or null when nothing was realised. */
    public function averageValueMinor(): ?int
    {
        return $this->realized > 0 ? intdiv($this->revenueMinor, $this->realized) : null;
    }

    /**
     * No-shows as a share of the bookings that were supposed to happen — the
     * realised set, which already includes the no-shows themselves. Null (not zero)
     * when there is nothing to divide by: "no data" and "nobody missed" are
     * different answers and the UI must be able to tell them apart.
     */
    public function noShowRateBps(): ?int
    {
        return $this->realized > 0 ? intdiv($this->noShow * 10000, $this->realized) : null;
    }

    /** Cancellations as a share of everything booked for the range. */
    public function cancelRateBps(): ?int
    {
        return $this->bookings > 0 ? intdiv($this->canceled * 10000, $this->bookings) : null;
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'bookings' => $this->bookings,
            'realized' => $this->realized,
            'canceled' => $this->canceled,
            'no_show' => $this->noShow,
            'revenue_minor' => $this->revenueMinor,
            'customers' => $this->customers,
            'average_value_minor' => $this->averageValueMinor(),
            'no_show_rate_bps' => $this->noShowRateBps(),
            'cancel_rate_bps' => $this->cancelRateBps(),
        ];
    }
}
