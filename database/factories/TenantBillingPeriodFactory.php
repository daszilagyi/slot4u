<?php

namespace Database\Factories;

use App\Enums\BillingPeriodStatus;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantBillingPeriod>
 */
class TenantBillingPeriodFactory extends Factory
{
    protected $model = TenantBillingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'period' => fake()->dateTimeThisYear()->format('Y-m'),
            'turnover_minor' => 0,
            'commission_minor' => 0,
            'cap_reached' => false,
            'status' => BillingPeriodStatus::Open,
            'invoice_id' => null,
            'recomputed_at' => null,
        ];
    }

    public function invoiced(): static
    {
        return $this->state(fn () => ['status' => BillingPeriodStatus::Invoiced]);
    }
}
