<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\NotificationLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    protected $model = NotificationLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => NotificationType::BookingConfirmed,
            'channel' => 'email',
            'recipient' => $this->faker->safeEmail(),
            'status' => NotificationStatus::Pending,
            'dedupe_key' => null,
            'sent_at' => null,
            'error' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }
}
