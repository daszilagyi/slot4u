<?php

namespace App\Listeners;

use App\Actions\Commission\UpsertBookingCommissionItem;
use App\Events\BookingCreated;
use App\Events\BookingPriceChanged;
use App\Events\BookingStatusChanged;

/**
 * Keeps the commission ledger in step with the booking lifecycle (docs/10 §6.3):
 * whenever a booking is created or changes status, reconcile its ledger entry and
 * recompute the affected period.
 *
 * Listens to all three events so it catches a booking created straight into a
 * billable status (BookingCreated), every later transition — confirmed,
 * completed, no_show, cancellation (BookingStatusChanged) — and an admin editing
 * the list price, which is the commission base (BookingPriceChanged, SLO-126;
 * without it the ledger would keep the old amount until the next status change,
 * if one ever came). The action is idempotent on the booking, so overlapping
 * paths still yield exactly one ledger entry.
 *
 * Runs synchronously inside the dispatching transaction, keeping the ledger
 * atomic with the status change (a rolled-back booking rolls back its commission).
 */
class RecordBookingCommission
{
    public function __construct(private readonly UpsertBookingCommissionItem $upsert) {}

    public function handle(BookingCreated|BookingStatusChanged|BookingPriceChanged $event): void
    {
        ($this->upsert)($event->booking);
    }
}
