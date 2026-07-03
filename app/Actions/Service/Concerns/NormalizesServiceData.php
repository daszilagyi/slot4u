<?php

namespace App\Actions\Service\Concerns;

use App\Enums\BookingMode;

/**
 * Strips mode-irrelevant fields to safe defaults so a service never carries
 * stale data from a booking mode it no longer uses (e.g. a duration left over
 * after switching to event_based). Keeps the row honest regardless of what the
 * form submitted.
 */
trait NormalizesServiceData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalize(array $data): array
    {
        $mode = BookingMode::from($data['booking_mode']);

        // Duration applies to time-slotted rental and duration_based only.
        $durationModes = $mode->requiresDuration() || $mode === BookingMode::ResourceRental;
        if (! $durationModes) {
            $data['duration_minutes'] = null;
        }

        if (! $mode->supportsBuffers()) {
            $data['buffer_before_minutes'] = 0;
            $data['buffer_after_minutes'] = 0;
        }

        if (! $mode->requiresCapacity()) {
            $data['capacity'] = null;
        }

        if (! $mode->supportsWaitlist()) {
            $data['waitlist_enabled'] = false;
        }

        $data['settings'] = $this->normalizeSettings($mode, (array) ($data['settings'] ?? []));

        return $data;
    }

    /**
     * Keep only the settings keys meaningful for the mode; null when none apply.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function normalizeSettings(BookingMode $mode, array $settings): ?array
    {
        $kept = match ($mode) {
            BookingMode::NoTimeSlot => array_intersect_key($settings, array_flip(['fulfillment_type'])),
            BookingMode::ResourceRental => array_intersect_key($settings, array_flip([
                'min_duration_minutes', 'max_duration_minutes', 'deposit_minor',
            ])),
            BookingMode::QuoteRequest => array_intersect_key($settings, array_flip(['quote_fields'])),
            default => [],
        };

        return $kept === [] ? null : $kept;
    }
}
