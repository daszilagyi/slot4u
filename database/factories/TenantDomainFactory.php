<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    protected $model = TenantDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            // Unique and never inside the central domain, so a factory-made row
            // can never accidentally shadow a tenant's canonical subdomain.
            'domain' => 'book-'.fake()->unique()->numerify('#####').'.example.com',
            'verification_token' => Str::random(32),
            'verified_at' => null,
            'is_primary' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
            'last_checked_at' => now(),
            'last_error' => null,
        ]);
    }

    public function primary(): static
    {
        return $this->verified()->state(fn (): array => [
            'is_primary' => true,
        ]);
    }
}
