<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantCommissionOverride>
 */
class TenantCommissionOverrideFactory extends Factory
{
    protected $model = TenantCommissionOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // All-null override = pure inheritance from commission_settings.
        return [
            'tenant_id' => Tenant::factory(),
            'free_threshold_minor' => null,
            'rate_bps' => null,
            'rate_with_integration_bps' => null,
            'monthly_cap_minor' => null,
            'note' => null,
            'overridden_by' => null,
        ];
    }

    public function withRate(int $rateBps): static
    {
        return $this->state(fn () => ['rate_bps' => $rateBps]);
    }
}
