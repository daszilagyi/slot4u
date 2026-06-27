<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'monthly_price_minor' => 0,
            'currency' => 'HUF',
            'is_active' => true,
        ];
    }

    public function base(): static
    {
        return $this->state(fn () => [
            'code' => 'base',
            'name' => 'Base',
            'monthly_price_minor' => 0,
        ]);
    }
}
