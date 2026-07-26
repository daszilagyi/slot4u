<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a single refund (docs/02 `refunds`, SLO-131).
 *
 * A refund is recorded as an intent (`pending`) the moment the booking is
 * cancelled, and settled by the queued gateway call — so a gateway outage leaves
 * an auditable "we owe this customer" row rather than losing the obligation.
 */
enum RefundStatus: string
{
    /** Recorded, not yet confirmed by the gateway. */
    case Pending = 'pending';

    /** The gateway returned the money. */
    case Completed = 'completed';

    /** The gateway refused it — needs a manual retry by the tenant. */
    case Failed = 'failed';

    /** Whether the refund still counts as an outstanding obligation. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** Whether the money is (or is about to be) out the door — i.e. not refused. */
    public function countsAgainstPayment(): bool
    {
        return $this !== self::Failed;
    }
}
