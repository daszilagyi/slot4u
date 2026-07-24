<?php

namespace Database\Factories;

use App\Enums\CommissionCorrectionType;
use App\Enums\CommissionItemState;
use App\Models\Booking;
use App\Models\CommissionCorrection;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionCorrection>
 */
class CommissionCorrectionFactory extends Factory
{
    protected $model = CommissionCorrection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => CommissionCorrectionType::BookingAdjustment,
            'booking_id' => Booking::factory(),
            'source_period' => now()->subMonth()->format('Y-m'),
            'period' => now()->format('Y-m'),
            'corrected_amount_minor' => 0,
            'corrected_state' => CommissionItemState::Removed,
            'commission_delta_minor' => -1_00,
            'currency' => 'HUF',
        ];
    }

    public function carryOver(): static
    {
        return $this->state(fn (): array => [
            'type' => CommissionCorrectionType::CarryOver,
            'booking_id' => null,
            'corrected_amount_minor' => null,
            'corrected_state' => null,
        ]);
    }
}
