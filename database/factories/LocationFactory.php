<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->company(),
            'address' => [
                'line' => fake()->streetAddress(),
                'city' => fake()->city(),
                'postal_code' => fake()->postcode(),
            ],
            'phone' => fake()->phoneNumber(),
            'sort_order' => 0,
            'active' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
