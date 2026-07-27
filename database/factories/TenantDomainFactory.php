<?php

namespace Database\Factories;

use App\Enums\DomainProvisioningStatus;
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

    /** Registered at the edge with a live certificate — actually reachable. */
    public function provisioned(): static
    {
        return $this->verified()->state(fn (): array => [
            'provider_hostname_id' => (string) fake()->unique()->numerify('cf###############'),
            'provisioning_status' => DomainProvisioningStatus::Active,
            'certificate_status' => 'active',
            'provisioned_at' => now(),
        ]);
    }

    /** Registered, certificate still validating. */
    public function provisioningPending(): static
    {
        return $this->verified()->state(fn (): array => [
            'provider_hostname_id' => (string) fake()->unique()->numerify('cf###############'),
            'provisioning_status' => DomainProvisioningStatus::Pending,
            'certificate_status' => 'pending_validation',
            'provisioned_at' => now(),
        ]);
    }

    /** The provider refused; ownership is proven but the host does not serve. */
    public function provisioningFailed(string $error = 'Cloudflare rejected the request (HTTP 403).'): static
    {
        return $this->verified()->state(fn (): array => [
            'provisioning_status' => DomainProvisioningStatus::Failed,
            'provisioning_error' => $error,
        ]);
    }
}
