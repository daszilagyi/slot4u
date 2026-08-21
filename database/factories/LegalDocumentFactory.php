<?php

namespace Database\Factories;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    protected $model = LegalDocument::class;

    /**
     * The default is a platform document, because that is the scope a test has
     * to opt out of rather than into: `tenant_id` null is the value a careless
     * `forTenant()` omission would otherwise produce silently.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'type' => LegalDocumentType::Privacy,
            'version' => '1.0',
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'url' => null,
            'effective_from' => now()->subDay(),
            'created_by_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function platform(): static
    {
        return $this->state(['tenant_id' => null]);
    }

    public function terms(): static
    {
        return $this->state(['type' => LegalDocumentType::Terms]);
    }

    public function privacy(): static
    {
        return $this->state(['type' => LegalDocumentType::Privacy]);
    }

    public function version(string $version): static
    {
        return $this->state(['version' => $version]);
    }

    /** A version announced for later — visible to its author, not yet in force. */
    public function draft(): static
    {
        return $this->state(['effective_from' => now()->addWeek()]);
    }

    public function effectiveAt(\DateTimeInterface|string $moment): static
    {
        return $this->state(['effective_from' => $moment]);
    }

    /** Published as a link rather than as text held in the app. */
    public function linked(string $url = 'https://example.test/privacy'): static
    {
        return $this->state(['body' => null, 'url' => $url]);
    }
}
