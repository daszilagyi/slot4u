<?php

declare(strict_types=1);

namespace App\Listeners\Analytics;

use App\Enums\BookingStatus;
use App\Enums\ConversionStatus;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Jobs\Analytics\SendMetaConversion;
use App\Models\AnalyticsConversion;

/**
 * Sends the server-side conversion the moment a booking becomes a sale
 * (SLO-173).
 *
 * "Becomes a sale" is the same line the browser event uses: `confirmed` or
 * `completed`, reached either at creation or later. A booking still awaiting
 * approval or payment is not revenue, and reporting it as such would put money
 * into the tenant's ad platform for a slot that may lapse unpaid an hour later —
 * and Meta has no retraction for a conversion that turned out not to be one.
 *
 * The row this looks for exists only if the visitor allowed marketing
 * ({@see RecordConversionContext}), so a declining visitor reaches this listener
 * and finds nothing. There is no second consent check here on purpose: two
 * places that decide the same thing are two places that can disagree, and the
 * one holding the visitor's own cookie is the one that can be right.
 */
class SendConversionOnSale
{
    public function handle(BookingCreated|BookingStatusChanged $event): void
    {
        $status = $event instanceof BookingStatusChanged
            ? $event->to
            : $event->booking->status;

        if (! in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
            return;
        }

        // Without the tenant scope: this also runs from a queue worker or a
        // scheduled status sweep, where no tenant is bound. The row's own
        // booking_id is the scope.
        $conversion = AnalyticsConversion::withoutGlobalScopes()
            ->where('booking_id', $event->booking->getKey())
            ->where('provider', AnalyticsConversion::PROVIDER_META)
            ->where('status', ConversionStatus::Pending)
            ->first();

        if ($conversion === null) {
            return;
        }

        // Claim it: one atomic `pending → queued` update, and only the caller
        // whose UPDATE actually touched a row goes on to dispatch.
        //
        // Reading the row and then dispatching would be a race with a second
        // invocation of the same transition. When this was written there WAS a
        // guaranteed second invocation — event discovery double-registered every
        // listener (SLO-174, since fixed) — and the claim is what stopped Meta
        // being told about one sale twice. It stays because the other ways in
        // still exist: a replayed transition, a retried queue message, two
        // concurrent confirmations. Meta has no retraction for a duplicate.
        $claimed = AnalyticsConversion::withoutGlobalScopes()
            ->whereKey($conversion->getKey())
            ->where('status', ConversionStatus::Pending)
            ->update(['status' => ConversionStatus::Queued]);

        if ($claimed === 0) {
            return;
        }

        SendMetaConversion::dispatch($conversion->getKey());
    }
}
