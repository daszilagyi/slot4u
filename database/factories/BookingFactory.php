<?php

namespace Database\Factories;

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The model's creating hook fills a unique code; times are fixed (no
        // Date::now() at definition time) so tests are deterministic.
        return [
            'tenant_id' => Tenant::factory(),
            'customer_id' => null,
            'service_id' => Service::factory(),
            'booking_mode' => BookingMode::DurationBased,
            'staff_id' => null,
            'room_id' => null,
            'event_id' => null,
            'starts_at' => '2026-09-01 10:00:00',
            'ends_at' => '2026-09-01 11:00:00',
            'status' => BookingStatus::Confirmed,
            'party_size' => 1,
            'price_minor' => 500000,
            'currency' => 'HUF',
            'notes' => null,
            'source' => BookingSource::Admin,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function status(BookingStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function startingAt(string $startsAt, string $endsAt): static
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
