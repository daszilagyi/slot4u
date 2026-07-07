<?php

namespace Database\Factories;

use App\Models\QuoteRequest;
use App\Models\QuoteRequestMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteRequestMessage>
 */
class QuoteRequestMessageFactory extends Factory
{
    protected $model = QuoteRequestMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'quote_request_id' => QuoteRequest::factory(),
            'user_id' => User::factory(),
            'body' => 'Köszönjük a megkeresést, hamarosan küldjük az ajánlatot.',
        ];
    }

    public function forQuoteRequest(QuoteRequest $quoteRequest): static
    {
        return $this->state([
            'tenant_id' => $quoteRequest->tenant_id,
            'quote_request_id' => $quoteRequest->id,
        ]);
    }
}
