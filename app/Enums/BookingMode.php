<?php

namespace App\Enums;

/**
 * The five booking modes a service can run in (docs/04). `manual_approval` is
 * NOT a mode of its own — it is the `requires_approval` flag layered on any of
 * these (docs/04 §5), so the discriminator has five values, not six.
 *
 * The predicate helpers drive both server-side validation (ServiceRequest) and,
 * mirrored through the frontend BookingMode metadata, which fields the service
 * form shows. Keeping the rules here means the two never drift.
 */
enum BookingMode: string
{
    case NoTimeSlot = 'no_time_slot';
    case DurationBased = 'duration_based';
    case EventBased = 'event_based';
    case ResourceRental = 'resource_rental';
    case QuoteRequest = 'quote_request';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }

    /** duration_based needs a slot length; the others do not require one. */
    public function requiresDuration(): bool
    {
        return $this === self::DurationBased;
    }

    /** event_based advertises a fixed capacity per occurrence. */
    public function requiresCapacity(): bool
    {
        return $this === self::EventBased;
    }

    /** Buffer padding only makes sense for time-slotted modes. */
    public function supportsBuffers(): bool
    {
        return $this === self::DurationBased || $this === self::ResourceRental;
    }

    /** Only advertised events (mode 3) carry a waitlist. */
    public function supportsWaitlist(): bool
    {
        return $this === self::EventBased;
    }

    /** resource_rental books a room/equipment resource rather than staff. */
    public function usesRoomResource(): bool
    {
        return $this === self::ResourceRental;
    }

    /**
     * A feature flag the mode itself requires to be enabled for the tenant, or
     * null when the mode is always available. quote_request depends on
     * feature_quote_request (docs/03).
     */
    public function requiredFeature(): ?Feature
    {
        return match ($this) {
            self::QuoteRequest => Feature::QuoteRequest,
            default => null,
        };
    }
}
