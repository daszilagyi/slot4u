<?php

namespace Database\Factories;

use App\Enums\ConsentContext;
use App\Models\LegalConsent;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalConsent>
 */
class LegalConsentFactory extends Factory
{
    protected $model = LegalConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'legal_document_id' => LegalDocument::factory(),
            'user_id' => null,
            'email' => $this->faker->safeEmail(),
            'context' => ConsentContext::Booking,
            'accepted_at' => now(),
            'ip_address' => $this->faker->ipv4(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    /** An acceptance by someone with an account — the email handle goes away. */
    public function byUser(User $user): static
    {
        return $this->state(['user_id' => $user->id, 'email' => null]);
    }

    public function byGuest(string $email): static
    {
        return $this->state(['user_id' => null, 'email' => mb_strtolower($email)]);
    }

    public function forDocument(LegalDocument $document): static
    {
        return $this->state(['legal_document_id' => $document->id]);
    }

    public function context(ConsentContext $context): static
    {
        return $this->state(['context' => $context]);
    }
}
