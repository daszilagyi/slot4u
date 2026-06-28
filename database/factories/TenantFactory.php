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
}
