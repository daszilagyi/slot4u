<?php

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Fixed offsets (no Date::now() at definition time — deterministic tests
        // stamp concrete times via state).
        return [
            'tenant_id' => Tenant::factory(),
            'service_id' => Service::factory(),
            'staff_id' => null,
            'room_id' => null,
            'starts_at' => '2026-09-01 10:00:00',
            'ends_at' => '2026-09-01 11:00:00',
            'capacity' => 10,
            'booked_count' => 0,
            'waitlist_enabled' => false,
            'status' => EventStatus::Scheduled,
            'recurrence_rule' => null,
            'series_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function at(string $startsAt, string $endsAt): static
    {
        return $this->state(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    public function withBookings(int $count): static
    {
        return $this->state(['booked_count' => $count]);
    }

    public function canceled(): static
    {
        return $this->state(['status' => EventStatus::Canceled]);
    }
}
