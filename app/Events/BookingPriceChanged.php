<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin changed a booking's list price (SLO-126, docs/10 §3.3).
 *
 * Its own event rather than a general "booking updated": the list price is the
 * commission base, so this is the one field whose edit has to reach the ledger,
 * and a narrow event keeps the commission listener from re-running on every
 * unrelated booking edit.
 *
 * Dispatched inside the updating transaction, so the ledger stays atomic with
 * the price — a rolled-back edit rolls back its commission entry too.
 */
class BookingPriceChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Booking $booking,
        public readonly int $from,
        public readonly int $to,
        public readonly ?User $actor = null,
    ) {}
}
