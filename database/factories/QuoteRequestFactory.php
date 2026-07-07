<?php

namespace Database\Factories;

use App\Enums\QuoteRequestStatus;
use App\Models\QuoteRequest;
use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRequest>
 */
class QuoteRequestFactory extends Factory
{
    protected $model = QuoteRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'service_id' => Service::factory(),
            'customer_id' => null,
            'status' => QuoteRequestStatus::New,
            'parameters' => ['guests' => '120', 'date' => '2026-10-10'],
            'price_minor' => null,
            'currency' => null,
            'valid_until' => null,
            'internal_notes' => null,
            'booking_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(['tenant_id' => $tenant->id]);
    }

    public function status(QuoteRequestStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** A worked-up request holding a price ready to be accepted. */
    public function quoted(int $priceMinor = 250000, string $currency = 'HUF'): static
    {
        return $this->state([
            'status' => QuoteRequestStatus::Quoted,
            'price_minor' => $priceMinor,
            'currency' => $currency,
            'valid_until' => '2026-12-31 23:59:59',
        ]);
    }
}
