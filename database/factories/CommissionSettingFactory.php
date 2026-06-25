<?php

namespace Database\Factories;

use App\Models\CommissionSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionSetting>
 */
class CommissionSettingFactory extends Factory
{
    protected $model = CommissionSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // docs/10 §2.1 platform defaults.
        return [
            'free_threshold_minor' => 1_000_000,
            'rate_bps' => 100,
            'rate_with_integration_bps' => 150,
            'monthly_cap_minor' => 5_000_000,
            'currency' => 'HUF',
            'effective_from' => now(),
            'created_by' => null,
        ];
    }
}
