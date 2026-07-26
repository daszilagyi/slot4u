<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a tenant refunds when a paid booking is cancelled (docs/04 §5, SLO-131).
 * Stored per tenant in `settings.refund_policy`; the partial share comes from
 * `settings.refund_percent_bps`.
 *
 * This is the DEFAULT applied automatically — an admin can always issue a
 * different amount by hand from the booking page.
 */
enum RefundPolicy: string
{
    /** Nothing is returned automatically (the default — the safe one). */
    case None = 'none';

    /** The whole settled amount is returned. */
    case Full = 'full';

    /** A share of the settled amount (`refund_percent_bps`) is returned. */
    case Partial = 'partial';

    /**
     * The refundable amount for a settled payment under this policy. Floor
     * rounding on integer minor units — never float, and never more than was paid.
     */
    public function amountFor(int $paidMinor, int $percentBps): int
    {
        $amount = match ($this) {
            self::None => 0,
            self::Full => $paidMinor,
            self::Partial => intdiv($paidMinor * max(0, $percentBps), 10_000),
        };

        return max(0, min($amount, $paidMinor));
    }
}
