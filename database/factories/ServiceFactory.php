<?php

namespace Database\Factories;

use App\Enums\BookingMode;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'category_id' => null,
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'booking_mode' => BookingMode::DurationBased,
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'price_minor' => fake()->numberBetween(0, 5_000_000),
            'currency' => 'HUF',
            'capacity' => null,
            'requires_staff' => true,
            'requires_room' => false,
            'requires_approval' => false,
            'waitlist_enabled' => false,
            'online_payment_required' => false,
            'settings' => null,
            'active' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function mode(BookingMode $mode): static
    {
        return $this->state(['booking_mode' => $mode]);
    }

    public function eventBased(): static
    {
        return $this->state([
            'booking_mode' => BookingMode::EventBased,
            'duration_minutes' => null,
            'capacity' => 10,
            'requires_staff' => false,
        ]);
    }
}
