<?php

namespace Database\Factories;

use App\Enums\InvoiceProvider;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'payment_id' => Payment::factory(),
            'provider' => InvoiceProvider::Sandbox,
            'amount_minor' => fake()->numberBetween(1_00, 500_00),
            'currency' => 'HUF',
            'status' => InvoiceStatus::Pending,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Issued,
            'number' => 'SBX-'.fake()->unique()->numerify('######'),
            'issued_at' => now(),
        ]);
    }

    public function failed(string $error = 'provider unavailable'): static
    {
        return $this->state(fn (): array => [
            'status' => InvoiceStatus::Failed,
            'error' => $error,
        ]);
    }

    /** Attach the invoice to a settled payment, inheriting its booking and amount. */
    public function forPayment(Payment $payment): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $payment->tenant_id,
            'booking_id' => $payment->booking_id,
            'payment_id' => $payment->getKey(),
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
        ]);
    }
}
