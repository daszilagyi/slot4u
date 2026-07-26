<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a single customer payment attempt (docs/02 `payments`, SLO-130).
 *
 * One row per checkout attempt: it opens as `pending` when the customer is sent
 * to the gateway, and the gateway callback (or the pending-payment expiry job)
 * settles it. `refunded` is written by the refund flow (SLO-131).
 */
enum PaymentStatus: string
{
    /** Checkout started; the gateway has not reported an outcome yet. */
    case Pending = 'pending';

    /** The customer paid — the booking may be confirmed. */
    case Paid = 'paid';

    /** Refused, cancelled or abandoned (the hold expired). */
    case Failed = 'failed';

    /** Paid and later refunded (SLO-131). */
    case Refunded = 'refunded';

    /** Whether the attempt is still awaiting an outcome from the gateway. */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /** Whether the customer's money has reached the tenant (paid, not yet refunded). */
    public function isSettled(): bool
    {
        return $this === self::Paid;
    }
}
