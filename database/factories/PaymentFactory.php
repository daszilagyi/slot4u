<?php

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => Booking::factory(),
            'provider' => PaymentProvider::Sandbox,
            'provider_ref' => 'sbx_'.fake()->unique()->lexify('????????????????'),
            'amount_minor' => fake()->numberBetween(1_00, 500_00),
            'currency' => 'HUF',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => PaymentStatus::Failed]);
    }

    /** Attach the payment to a booking, inheriting its tenant and price. */
    public function forBooking(Booking $booking): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->getKey(),
            'amount_minor' => $booking->price_minor,
            'currency' => $booking->currency,
        ]);
    }
}
