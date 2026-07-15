<?php

namespace Database\Factories;

use App\Enums\CommissionItemState;
use App\Models\Booking;
use App\Models\BookingCommissionItem;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingCommissionItem>
 */
class BookingCommissionItemFactory extends Factory
{
    protected $model = BookingCommissionItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'period' => now()->format('Y-m'),
            'amount_minor' => fake()->numberBetween(1_00, 5_000_00),
            'rate_bps' => 100,
            'realized_at' => now(),
            'state' => CommissionItemState::Billable,
            'settings_id' => CommissionSetting::factory(),
            'currency' => 'HUF',
        ];
    }

    public function removed(): static
    {
        return $this->state(fn (): array => ['state' => CommissionItemState::Removed]);
    }
}
