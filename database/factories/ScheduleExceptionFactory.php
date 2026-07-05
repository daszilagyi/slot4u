<?php

namespace Database\Factories;

use App\Enums\ScheduleExceptionType;
use App\Models\ScheduleException;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
{
    protected $model = ScheduleException::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'schedulable_type' => 'staff',
            'schedulable_id' => Staff::factory(),
            'date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
            'type' => ScheduleExceptionType::Off,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function forSchedulable(Model $schedulable): static
    {
        return $this->state([
            'schedulable_type' => $schedulable->getMorphClass(),
            'schedulable_id' => $schedulable->getKey(),
        ]);
    }

    public function extra(): static
    {
        return $this->state(['type' => ScheduleExceptionType::Extra]);
    }
}
