<?php

namespace Database\Factories;

use App\Enums\PrivacyRequestStatus;
use App\Enums\PrivacyRequestType;
use App\Models\PrivacyRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivacyRequest>
 */
class PrivacyRequestFactory extends Factory
{
    protected $model = PrivacyRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'type' => PrivacyRequestType::Erasure,
            'status' => PrivacyRequestStatus::Pending,
            'resolution_note' => null,
            'resolved_at' => null,
            'resolved_by_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function export(): static
    {
        return $this->state([
            'type' => PrivacyRequestType::Export,
            'status' => PrivacyRequestStatus::Completed,
            'resolved_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => PrivacyRequestStatus::Completed,
            'resolved_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Folyamatban lévő jogvita miatt elutasítva.'): static
    {
        return $this->state([
            'status' => PrivacyRequestStatus::Rejected,
            'resolution_note' => $reason,
            'resolved_at' => now(),
        ]);
    }
}
