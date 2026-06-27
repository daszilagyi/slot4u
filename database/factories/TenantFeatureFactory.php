<?php

namespace Database\Factories;

use App\Enums\Feature;
use App\Models\TenantFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantFeature>
 */
class TenantFeatureFactory extends Factory
{
    protected $model = TenantFeature::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature_code' => fake()->randomElement(Feature::cases()),
            'enabled' => true,
            'overridden_by' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['enabled' => false]);
    }
}
