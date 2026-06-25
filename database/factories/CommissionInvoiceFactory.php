<?php

namespace Database\Factories;

use App\Enums\CommissionInvoiceStatus;
use App\Models\CommissionInvoice;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionInvoice>
 */
class CommissionInvoiceFactory extends Factory
{
    protected $model = CommissionInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $net = 20_000;
        $vatBps = 2700;
        $vat = intdiv($net * $vatBps, 10_000);

        return [
            'tenant_id' => Tenant::factory(),
            'period' => fake()->dateTimeThisYear()->format('Y-m'),
            'turnover_minor' => 3_000_000,
            'billable_base_minor' => 2_000_000,
            'commission_net_minor' => $net,
            'vat_bps' => $vatBps,
            'vat_minor' => $vat,
            'total_gross_minor' => $net + $vat,
            'currency' => 'HUF',
            'status' => CommissionInvoiceStatus::Draft,
            'issued_at' => null,
            'due_at' => null,
            'paid_at' => null,
            'paid_method' => null,
            'provider' => null,
            'provider_ref' => null,
            'pdf_path' => null,
        ];
    }

    public function issued(): static
    {
        return $this->state(fn () => [
            'status' => CommissionInvoiceStatus::Issued,
            'issued_at' => now(),
            'due_at' => now()->addDays(8),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => CommissionInvoiceStatus::Paid,
            'issued_at' => now()->subDays(5),
            'paid_at' => now(),
        ]);
    }
}
