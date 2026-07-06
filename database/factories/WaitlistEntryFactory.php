<?php

namespace Database\Factories;

use App\Enums\WaitlistStatus;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistEntry>
 */
class WaitlistEntryFactory extends Factory
{
    protected $model = WaitlistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'event_id' => Event::factory(),
            'service_id' => null,
            'customer_id' => User::factory(),
            'party_size' => 1,
            'position' => 1,
            'status' => WaitlistStatus::Waiting,
            'offered_until' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function forEvent(Event $event): static
    {
        return $this->state(['tenant_id' => $event->tenant_id, 'event_id' => $event->id]);
    }

    public function position(int $position): static
    {
        return $this->state(['position' => $position]);
    }

    public function offered(string $offeredUntil): static
    {
        return $this->state(['status' => WaitlistStatus::Offered, 'offered_until' => $offeredUntil]);
    }
}
