<?php

declare(strict_types=1);

namespace App\Services\Commission;

/**
 * The platform-wide commission picture for one month, as the superadmin
 * dashboard shows it (docs/10 §10): how much slot4u earned (the MRR proxy), how
 * tenants move through the activation funnel, who the biggest earners are, and
 * which unpaid invoices are about to trigger a suspension.
 *
 * Money is integer minor units; counts are tenant/invoice tallies. A null
 * threshold/cap means no pricing model is configured yet (`configured = false`),
 * so those figures are unknown rather than zero.
 */
final readonly class CommissionStatistics
{
    /**
     * @param  list<TopTenantStat>  $topTenants  Highest turnover first.
     * @param  list<OverdueInvoiceStat>  $overdueInvoices  Soonest suspension first.
     */
    public function __construct(
        public string $period,
        public string $currency,
        public bool $configured,
        public ?int $freeThresholdMinor,
        public ?int $monthlyCapMinor,
        // Revenue — the month's billable net commission is slot4u's MRR proxy,
        // split by where each period sits in the billing lifecycle. The accrual is
        // the gross figure before the credits a closed period's later change
        // produced (§8.2); the correction total is those credits (<= 0), and the
        // net is what the invoices actually charge. Net != accrued + correction
        // when a period was voided or credited below zero (the remainder carries
        // over to the next month instead of showing as negative revenue).
        public int $turnoverTotalMinor,
        public int $commissionAccruedMinor,
        public int $correctionTotalMinor,
        public int $commissionNetMinor,
        public int $commissionOpenMinor,
        public int $commissionInvoicedMinor,
        public int $commissionPaidMinor,
        public int $commissionOverdueMinor,
        // Activation funnel — operational tenants narrowing down to paying ones.
        public int $activeTenants,
        public int $tenantsWithTurnover,
        public int $payingTenants,
        // Turnover but no commission yet: below the free threshold, "stuck".
        public int $stuckTenants,
        public int $capReachedTenants,
        public array $topTenants,
        public array $overdueInvoices,
        public int $overdueCount,
        public int $overdueTotalMinor,
    ) {}
}
