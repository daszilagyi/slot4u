<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\CommissionCorrection;

/**
 * What a {@see CommissionCorrection} row credits (docs/10 §8.2).
 */
enum CommissionCorrectionType: string
{
    /**
     * A booking billed in an already-closed period later shrank or stopped being
     * commission-bearing; the difference is credited to the open period.
     */
    case BookingAdjustment = 'booking_adjustment';

    /**
     * The unused remainder of a credit that exceeded a period's own commission,
     * moved forward so it is not forfeited.
     */
    case CarryOver = 'carry_over';
}
