<?php

namespace Database\Factories;

use App\Enums\PlanLimitKey;
use App\Models\Plan;
use App\Models\PlanLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanLimit>
 */
class PlanLimitFactory extends Factory
{
    protected $model = PlanLimit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'key' => fake()->randomElement(PlanLimitKey::cases()),
            'value' => fake()->numberBetween(1, 100),
        ];
    }
}
