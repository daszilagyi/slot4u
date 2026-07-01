<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Location;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => Location::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => RoomType::Room->value,
            'capacity' => fake()->numberBetween(1, 20),
            'description' => null,
            'active' => true,
        ];
    }

    /** Attach the room to a location, inheriting its tenant. */
    public function forLocation(Location $location): static
    {
        return $this->state([
            'location_id' => $location->id,
            'tenant_id' => $location->tenant_id,
        ]);
    }

    public function equipment(): static
    {
        return $this->state(['type' => RoomType::Equipment->value]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
