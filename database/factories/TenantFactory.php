<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => fake()->unique()->domainWord(),
            'status' => TenantStatus::Trial,
            'timezone' => 'Europe/Budapest',
            'locale' => 'hu',
            'branding' => null,
            'settings' => null,
        ];
    }

    public function trial(): static
    {
        return $this->state(fn () => [
            'status' => TenantStatus::Trial,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Active]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Suspended]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => TenantStatus::Archived]);
    }

    /**
     * A sales-demo workspace (SLO-182, docs/20 §3.1). Active rather than trial:
     * a demo that expires is a demo that is broken the week nobody looked at it,
     * and the demo tenants are exempt from the billing close that would otherwise
     * make "active without paying" a contradiction.
     */
    public function demo(): static
    {
        return $this->state(fn () => [
            'is_demo' => true,
            'status' => TenantStatus::Active,
        ]);
    }
}
