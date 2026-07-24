<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\CommissionCorrectionType;
use App\Enums\CommissionItemState;
use App\Models\BookingCommissionItem;
use App\Models\CommissionCorrection;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\BillingPeriodClock;
use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionItem;
use App\Services\Commission\ResolveTenantCommissionSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Credits an open period for a booking that changed after its period was already
 * invoiced (docs/10 §8.2/§15.5).
 *
 * A closed period is accounting-stable: neither its aggregate nor its ledger
 * entries are rewritten. Instead the period is *replayed* — §2.3 over its ledger
 * with the booking's new reality substituted — and the difference against what it
 * has been charged so far becomes a negative correction on the tenant's current
 * open period, reducing the next monthly invoice.
 *
 * Replaying is what makes the credit exact under the marginal threshold and the
 * monthly cap: there is no meaningful per-booking share of a period's commission
 * to hand back (a booking below the threshold contributed nothing; one above a
 * reached cap likewise), only the difference the change makes to the whole month.
 *
 * Credits only. A change that would *raise* a closed period's commission (a late
 * price increase, a re-confirmation) is never charged retroactively — the tenant
 * keeps the credit already given, and the recorded reality stays where it was, so
 * a later decrease is measured from there and never credited twice.
 */
final class RecordClosedPeriodCorrection
{
    /**
     * How many months forward to look for an open period. A tenant would have to
     * hold a year of consecutive closed periods for this to bind; the bound only
     * exists so a corrupt state cannot spin.
     */
    private const int MAX_PERIOD_LOOKAHEAD = 24;

    public function __construct(
        private readonly ResolveTenantCommissionSettings $resolveSettings,
        private readonly CommissionCalculator $calculator,
        private readonly BillingPeriodClock $clock,
        private readonly RecomputeTenantPeriod $recompute,
    ) {}

    /**
     * @param  BookingCommissionItem  $item  The frozen ledger entry in the closed period.
     * @param  int  $amountMinor  The booking's list price now.
     * @param  CommissionItemState  $state  Whether it is commission-bearing now.
     */
    public function __invoke(
        Tenant $tenant,
        BookingCommissionItem $item,
        int $amountMinor,
        CommissionItemState $state,
    ): ?CommissionCorrection {
        // Serialise on the closed period's row: two bookings of the same period
        // changing concurrently would otherwise both measure against the same
        // "charged so far" baseline and credit the difference twice.
        return DB::transaction(fn (): ?CommissionCorrection => $this->correct($tenant, $item, $amountMinor, $state));
    }

    private function correct(
        Tenant $tenant,
        BookingCommissionItem $item,
        int $amountMinor,
        CommissionItemState $state,
    ): ?CommissionCorrection {
        $sourcePeriod = $item->period;

        $aggregate = TenantBillingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('period', $sourcePeriod)
            ->lockForUpdate()
            ->first();

        if (! $aggregate instanceof TenantBillingPeriod) {
            return null;
        }

        /** @var array<int, CommissionCorrection> $creditedByBooking */
        $creditedByBooking = $this->latestCreditedRealityByBooking($tenant, $sourcePeriod);
        $alreadyCredited = $creditedByBooking[$item->booking_id] ?? null;

        // The reality this booking was last credited for — the original ledger
        // snapshot until a correction moved it.
        $creditedAmount = $alreadyCredited->corrected_amount_minor ?? $item->amount_minor;
        $creditedState = $alreadyCredited->corrected_state ?? $item->state;

        // Nothing moved since the last credit → no-op. The lifecycle listener runs
        // on every transition, so this is the common path.
        if ($amountMinor === $creditedAmount && $state === $creditedState) {
            return null;
        }

        try {
            $replayed = $this->replayPeriod($tenant, $sourcePeriod, $creditedByBooking, $item, $amountMinor, $state);
        } catch (RuntimeException) {
            // No commission settings effective for that period (docs/10 §6.4) —
            // it never priced anything, so there is nothing to credit.
            return null;
        }

        // What the closed period has been charged so far: its frozen commission
        // less the credits already issued for its own bookings. Carry-over rows
        // are excluded — they relocate an unabsorbed credit, they do not change
        // what this period charged.
        $charged = $aggregate->commission_minor + $this->creditedSoFar($tenant, $sourcePeriod);
        $delta = $replayed - $charged;

        if ($delta >= 0) {
            return null;
        }

        $targetPeriod = $this->openPeriodFor($tenant);

        $correction = new CommissionCorrection([
            'type' => CommissionCorrectionType::BookingAdjustment,
            'booking_id' => $item->booking_id,
            'source_period' => $sourcePeriod,
            'period' => $targetPeriod,
            'corrected_amount_minor' => $amountMinor,
            'corrected_state' => $state,
            'commission_delta_minor' => $delta,
            'currency' => $item->currency,
        ]);
        $correction->tenant_id = $tenant->getKey();
        // saveQuietly: the BelongsToTenant creating hook stamps the *bound* tenant
        // over an explicit tenant_id, and this can run from a tenant-less
        // scheduled job (soft-hold expiry, waitlist sweep) — the booking's tenant
        // is the authority here, not the ambient one.
        $correction->saveQuietly();

        ($this->recompute)($tenant->getKey(), $targetPeriod);

        return $correction;
    }

    /**
     * Replay §2.3 over the closed period's ledger with each booking's currently
     * credited reality substituted, and this booking's new reality on top.
     * Removed items drop out of the chronology exactly as they would have.
     *
     * @param  array<int, CommissionCorrection>  $creditedByBooking
     */
    private function replayPeriod(
        Tenant $tenant,
        string $period,
        array $creditedByBooking,
        BookingCommissionItem $changed,
        int $amountMinor,
        CommissionItemState $state,
    ): int {
        /** @var list<CommissionItem> $items */
        $items = [];

        $ledger = BookingCommissionItem::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('period', $period)
            ->orderBy('realized_at')
            ->orderBy('id')
            ->get();

        foreach ($ledger as $entry) {
            $isChanged = $entry->getKey() === $changed->getKey();
            $credited = $creditedByBooking[$entry->booking_id] ?? null;

            $entryState = $isChanged ? $state : ($credited->corrected_state ?? $entry->state);

            if ($entryState !== CommissionItemState::Billable) {
                continue;
            }

            $items[] = new CommissionItem(
                amountMinor: $isChanged ? $amountMinor : ($credited->corrected_amount_minor ?? $entry->amount_minor),
                rateBps: $entry->rate_bps,
            );
        }

        // The same settings instant the period was priced with (§2.4) — today's
        // threshold or cap must not leak into a past month's replay.
        $settings = $this->resolveSettings->resolve(
            $tenant,
            $this->clock->referenceInstant($period, $tenant->timezone),
        );

        return $this->calculator
            ->calculate($items, $settings->freeThresholdMinor, $settings->monthlyCapMinor)
            ->commissionMinor;
    }

    /**
     * The latest booking-adjustment correction per booking for a closed period —
     * the reality each of its bookings was last credited for.
     *
     * @return array<int, CommissionCorrection>
     */
    private function latestCreditedRealityByBooking(Tenant $tenant, string $sourcePeriod): array
    {
        $latest = [];

        $corrections = CommissionCorrection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('source_period', $sourcePeriod)
            ->where('type', CommissionCorrectionType::BookingAdjustment->value)
            ->orderBy('id')
            ->get();

        foreach ($corrections as $correction) {
            if ($correction->booking_id !== null) {
                // Ordered by id, so the last write per booking wins.
                $latest[$correction->booking_id] = $correction;
            }
        }

        return $latest;
    }

    /** Sum of the credits already issued for a closed period's own bookings (<= 0). */
    private function creditedSoFar(Tenant $tenant, string $sourcePeriod): int
    {
        return (int) CommissionCorrection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('source_period', $sourcePeriod)
            ->where('type', CommissionCorrectionType::BookingAdjustment->value)
            ->sum('commission_delta_minor');
    }

    /**
     * The period a credit can still land in: the one the tenant is accruing into,
     * or the first later one that is not already closed (a correction can arrive
     * after the current period was invoiced too).
     */
    private function openPeriodFor(Tenant $tenant): string
    {
        $period = $this->clock->currentPeriod($tenant->timezone);

        for ($i = 0; $i < self::MAX_PERIOD_LOOKAHEAD; $i++) {
            $aggregate = TenantBillingPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->where('period', $period)
                ->first();

            if (! $aggregate instanceof TenantBillingPeriod || $aggregate->status->isRecomputable()) {
                return $period;
            }

            $period = $this->clock->nextPeriod($period);
        }

        return $period;
    }
}
