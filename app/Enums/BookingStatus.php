<?php

namespace App\Enums;

use App\Actions\Booking\ChangeBookingStatus;

/**
 * The shared booking state machine (docs/04 §Közös állapotgép). Every one of the
 * six booking modes moves a booking through these states; the allowed transitions
 * are the single source of truth enforced by {@see ChangeBookingStatus}.
 *
 *   requested ──approve──▶ approved ──▶ pending_payment ──paid──▶ confirmed
 *       │                                     │ timeout/failed
 *       └──reject──▶ rejected                 └──▶ canceled
 *   confirmed ──▶ completed | canceled | no_show
 */
enum BookingStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Canceled = 'canceled';
    case Rejected = 'rejected';
    case NoShow = 'no_show';

    /**
     * The statuses this one may transition to (docs/04). A booking may be
     * canceled from any non-terminal state (customer or admin cancellation).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Requested => [self::Approved, self::Rejected, self::Canceled],
            self::Approved => [self::PendingPayment, self::Confirmed, self::Canceled],
            self::PendingPayment => [self::Confirmed, self::Canceled],
            self::Confirmed => [self::Completed, self::Canceled, self::NoShow],
            self::Completed, self::Canceled, self::Rejected, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Terminal states have no outgoing transitions.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether a booking in this status still occupies its slot for availability
     * (everything except the "gone" states — canceled/rejected/no_show).
     */
    public function occupiesSlot(): bool
    {
        return ! in_array($this, [self::Canceled, self::Rejected, self::NoShow], true);
    }

    /**
     * The status values that block a slot (SLO-22 availability).
     *
     * @return list<string>
     */
    public static function occupyingValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->occupiesSlot()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
