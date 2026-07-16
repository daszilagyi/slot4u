<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\BookingStatus;
use App\Enums\CommissionItemState;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Services\Commission\RateRaisingIntegrations;
use App\Services\Commission\ResolveTenantCommissionSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Reconciles a booking's commission ledger entry with its current status
 * (docs/10 §6.3), then recomputes the affected period. Idempotent on the
 * booking (unique `booking_id`): re-running for the same status is a no-op.
 *
 * The ledger entry's rate, settings version and realized-at instant are frozen
 * when the booking first becomes billable (§2.4 — a mid-month integration toggle
 * never applies retroactively); later transitions only sync the amount and flip
 * the state between billable and removed.
 *
 * Runs synchronously from a booking-lifecycle listener, inside the same
 * transaction as the status change, so the ledger stays atomic with the booking.
 */
final class UpsertBookingCommissionItem
{
    public function __construct(
        private readonly ResolveTenantCommissionSettings $resolveSettings,
        private readonly RateRaisingIntegrations $integrations,
        private readonly RecomputeTenantPeriod $recompute,
    ) {}

    public function __invoke(Booking $booking): void
    {
        $tenant = Tenant::withoutGlobalScopes()->find($booking->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $existing = BookingCommissionItem::withoutGlobalScopes()
            ->where('booking_id', $booking->getKey())
            ->first();

        // A booking whose commission was already invoiced in a now-closed period
        // is accounting-stable: leave its ledger entry and the frozen aggregate
        // untouched. Any later change is corrected in the current open period as a
        // credit, not by rewriting history (docs/10 §8.2 — the J6 mechanism).
        if ($existing instanceof BookingCommissionItem && $this->periodIsClosed($tenant, $existing->period)) {
            return;
        }

        if ($this->isBillable($booking, $existing)) {
            $item = $existing ?? $this->createFrozenItem($booking, $tenant);

            // No commission model configured for this instant (no effective
            // commission_settings) → nothing accrues; skip the ledger entirely.
            // This keeps booking flows working before/without a seeded pricing model.
            if ($item === null) {
                return;
            }

            // Keep the amount in sync with the (possibly admin-edited) list price;
            // the rate/period/realized_at snapshot stays frozen (§2.4).
            $item->amount_minor = $booking->price_minor;
            $item->state = CommissionItemState::Billable;
            $item->save();
            $period = $item->period;
        } elseif ($existing instanceof BookingCommissionItem) {
            // Became commission-free (24h+ cancellation / rejection): exclude it,
            // but keep the row for audit history (docs/10 §5.3).
            $existing->state = CommissionItemState::Removed;
            $existing->save();
            $period = $existing->period;
        } else {
            // Never was billable (e.g. requested → rejected) — no ledger, nothing to do.
            return;
        }

        ($this->recompute)($tenant->getKey(), $period);
    }

    /**
     * Whether the period holding an existing ledger entry has already been closed
     * (invoiced/paid) — such a period is frozen and must not be rewritten (§8.2).
     */
    private function periodIsClosed(Tenant $tenant, string $period): bool
    {
        $aggregate = TenantBillingPeriod::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('period', $period)
            ->first();

        return $aggregate instanceof TenantBillingPeriod && ! $aggregate->status->isRecomputable();
    }

    /**
     * Whether the booking is commission-bearing in its current status
     * (docs/10 §3.1).
     */
    private function isBillable(Booking $booking, ?BookingCommissionItem $existing): bool
    {
        return match ($booking->status) {
            BookingStatus::Confirmed,
            BookingStatus::Completed,
            BookingStatus::NoShow => true,
            BookingStatus::Canceled => $this->cancellationIsBillable($booking, $existing),
            BookingStatus::Requested,
            BookingStatus::Approved,
            BookingStatus::PendingPayment,
            BookingStatus::Rejected => false,
        };
    }

    /**
     * A cancellation stays billable when it is "late" (docs/10 §3.1): within 24h
     * of the appointment for a timed booking, or — for a timeless booking with no
     * `starts_at` — more than 24h after it became billable (the confirm-time grace
     * window, §15.6). An early cancellation is commission-free.
     *
     * A cancellation can only KEEP an already-billable booking billable; it never
     * makes a never-confirmed one billable. So without an existing ledger entry
     * (e.g. a `requested` hold that expired and auto-cancelled before it was ever
     * confirmed) it is commission-free — a requested booking never billed (§3.1).
     */
    private function cancellationIsBillable(Booking $booking, ?BookingCommissionItem $existing): bool
    {
        if (! $existing instanceof BookingCommissionItem || $booking->canceled_at === null) {
            return false;
        }

        if ($booking->starts_at !== null) {
            return $booking->canceled_at->greaterThan($booking->starts_at->copy()->subHours(24));
        }

        // Timeless mode: the 24h grace runs from when the booking became billable.
        return $booking->canceled_at->greaterThan($existing->realized_at->copy()->addHours(24));
    }

    /**
     * Insert the ledger entry with its rate/settings/realized-at snapshot frozen
     * at this moment (docs/10 §2.4). Race-safe on the unique `booking_id`: a
     * losing concurrent insert throws and we return the winning row. Returns null
     * when no commission settings are effective yet (no pricing model configured).
     */
    private function createFrozenItem(Booking $booking, Tenant $tenant): ?BookingCommissionItem
    {
        $realizedAt = Carbon::now();

        try {
            $settings = $this->resolveSettings->resolve($tenant, $realizedAt);
        } catch (\RuntimeException) {
            return null;
        }

        $rateBps = $settings->rateFor($this->integrations->active($tenant));

        $item = new BookingCommissionItem([
            'booking_id' => $booking->getKey(),
            'period' => $realizedAt->copy()->setTimezone($tenant->timezone)->format('Y-m'),
            'amount_minor' => $booking->price_minor,
            'rate_bps' => $rateBps,
            'realized_at' => $realizedAt,
            'state' => CommissionItemState::Billable,
            'settings_id' => $settings->settingsId,
            'currency' => $settings->currency,
        ]);
        $item->tenant_id = $tenant->getKey();

        try {
            $item->save();

            return $item;
        } catch (QueryException) {
            return BookingCommissionItem::withoutGlobalScopes()
                ->where('booking_id', $booking->getKey())
                ->firstOrFail();
        }
    }
}
