<?php

namespace Database\Factories;

use App\Models\Schedule;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Schedule>
 */
class ScheduleFactory extends Factory
{
    protected $model = Schedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $staff = Staff::factory();

        return [
            'tenant_id' => Tenant::factory(),
            'schedulable_type' => 'staff',
            'schedulable_id' => $staff,
            'location_id' => null,
            'day_of_week' => fake()->numberBetween(1, 7),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'valid_from' => null,
            'valid_until' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    /**
     * Attach the band to a concrete schedulable (staff or room).
     */
    public function forSchedulable(Model $schedulable): static
    {
        return $this->state([
            'schedulable_type' => $schedulable->getMorphClass(),
            'schedulable_id' => $schedulable->getKey(),
        ]);
    }

    public function onDay(int $dayOfWeek, string $start, string $end): static
    {
        return $this->state([
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }
}
