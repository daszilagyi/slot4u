<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payment_id' => Payment::factory(),
            'amount_minor' => fake()->numberBetween(1_00, 500_00),
            'currency' => 'HUF',
            'status' => RefundStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => RefundStatus::Completed,
            'refunded_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => RefundStatus::Failed]);
    }

    /** Attach the refund to a payment, inheriting its tenant and currency. */
    public function forPayment(Payment $payment): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $payment->tenant_id,
            'payment_id' => $payment->getKey(),
            'currency' => $payment->currency,
        ]);
    }
}
