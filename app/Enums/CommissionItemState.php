<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\BookingCommissionItem;

/**
 * Lifecycle of a single commission ledger entry (docs/10 §5.3).
 *
 * There is exactly one {@see BookingCommissionItem} row per booking
 * (unique `booking_id`); its state — not its existence — decides whether it
 * counts towards the period turnover. A booking that stops being commission
 * bearing (a 24h+ early cancellation, a rejection) flips to `removed` rather than
 * being deleted, so the ledger keeps a full, auditable history.
 */
enum CommissionItemState: string
{
    /** Counts towards the period turnover and commission. */
    case Billable = 'billable';

    /** Excluded from the calculation (24h+ cancellation / rejection). */
    case Removed = 'removed';
}
