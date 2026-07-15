<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\BillingPeriodStatus;
use App\Enums\CommissionItemState;
use App\Events\TenantPeriodRecomputed;
use App\Models\BookingCommissionItem;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionItem;
use App\Services\Commission\ResolveTenantCommissionSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes a tenant's monthly commission aggregate from the ledger
 * (docs/10 §6.2). The ledger (booking_commission_items) is the source of truth;
 * tenant_billing_periods is a derived cache rebuilt here deterministically — the
 * whole period is recomputed on every change, which sidesteps concurrent
 * marginal/cap allocation and is always correct (a period holds at most a few
 * thousand items).
 *
 * Runs inside a transaction with the aggregate row locked FOR UPDATE, so two
 * concurrent booking transitions on the same period serialise and the last
 * writer sees the full ledger (docs/10 §12/19). A closed (invoiced/paid) period
 * is accounting-stable and is never recomputed — corrections land in the current
 * open period instead (§8.2).
 */
final class RecomputeTenantPeriod
{
    public function __construct(
        private readonly ResolveTenantCommissionSettings $resolveSettings,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * @return TenantBillingPeriod|null The recomputed aggregate, or null when the
     *                                  tenant no longer exists.
     */
    public function __invoke(int $tenantId, string $period): ?TenantBillingPeriod
    {
        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return DB::transaction(function () use ($tenant, $tenantId, $period): TenantBillingPeriod {
            $aggregate = $this->lockOrCreate($tenantId, $period);

            // Closed periods are frozen (docs/10 §8.2); leave the row untouched.
            if (! $aggregate->status->isRecomputable()) {
                return $aggregate;
            }

            /** @var list<CommissionItem> $items */
            $items = BookingCommissionItem::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('period', $period)
                ->where('state', CommissionItemState::Billable->value)
                ->orderBy('realized_at')
                ->orderBy('id')
                ->get()
                ->map(fn (BookingCommissionItem $item): CommissionItem => new CommissionItem(
                    amountMinor: $item->amount_minor,
                    rateBps: $item->rate_bps,
                ))
                ->all();

            $settings = $this->resolveSettings->resolve($tenant, $this->periodReferenceInstant($period, $tenant->timezone));

            $result = $this->calculator->calculate(
                $items,
                $settings->freeThresholdMinor,
                $settings->monthlyCapMinor,
            );

            $aggregate->turnover_minor = $result->turnoverMinor;
            $aggregate->billable_base_minor = $result->billableBaseMinor;
            $aggregate->commission_minor = $result->commissionMinor;
            $aggregate->cap_reached = $result->capReached;
            $aggregate->recomputed_at = Carbon::now();
            $aggregate->save();

            TenantPeriodRecomputed::dispatch($tenantId, $period);

            return $aggregate;
        });
    }

    /**
     * Fetch the aggregate row under a FOR UPDATE lock, creating it first if it
     * does not exist yet. The unique(tenant_id, period) constraint makes the
     * create race-safe: a losing concurrent insert throws and we re-read the
     * winner under the lock.
     */
    private function lockOrCreate(int $tenantId, string $period): TenantBillingPeriod
    {
        $locked = $this->lockPeriod($tenantId, $period);

        if ($locked instanceof TenantBillingPeriod) {
            return $locked;
        }

        try {
            $row = new TenantBillingPeriod(['period' => $period, 'status' => BillingPeriodStatus::Open]);
            $row->tenant_id = $tenantId;
            $row->save();
        } catch (QueryException) {
            // A concurrent transaction created it first — fall through and lock it.
        }

        $locked = $this->lockPeriod($tenantId, $period);

        // The row exists now (we or the concurrent writer created it).
        assert($locked instanceof TenantBillingPeriod);

        return $locked;
    }

    private function lockPeriod(int $tenantId, string $period): ?TenantBillingPeriod
    {
        return TenantBillingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();
    }

    /**
     * The instant the period's effective commission settings are pinned to: the
     * end of the period's month in the tenant's timezone, but never in the future
     * (a still-open period pins to "now" so a settings version scheduled later
     * this month is not applied early). This keeps a past period auditable — it
     * reconstructs with the settings in force at its close (docs/10 §12/16).
     */
    private function periodReferenceInstant(string $period, string $timezone): Carbon
    {
        // Anchor to the first of the month before ->endOfMonth() so a short month
        // never overflows from createFromFormat filling the day from "today".
        $endOfPeriod = Carbon::createFromFormat('Y-m-d', $period.'-01', $timezone)
            ->endOfMonth()
            ->utc();

        $now = Carbon::now();

        return $endOfPeriod->greaterThan($now) ? $now : $endOfPeriod;
    }
}
