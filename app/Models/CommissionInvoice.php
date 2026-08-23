<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionInvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommissionInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Monthly slot4u → tenant commission invoice (docs/10 §5.5).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $period
 * @property int $turnover_minor
 * @property int $billable_base_minor
 * @property int $correction_minor
 * @property int $commission_net_minor
 * @property int $vat_bps
 * @property int $vat_minor
 * @property int $total_gross_minor
 * @property string $currency
 * @property CommissionInvoiceStatus $status
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property string|null $paid_method
 * @property string|null $provider
 * @property string|null $provider_ref
 * @property string|null $number the provider's human-readable document number (SLO-143)
 * @property string|null $pdf_path
 * @property string|null $provider_error last refusal from the provider; non-null means retryable
 * @property string|null $storno_ref
 * @property string|null $storno_pdf_path the void document — a SECOND file, never a replacement
 */
class CommissionInvoice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CommissionInvoiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'period',
        'turnover_minor',
        'billable_base_minor',
        'correction_minor',
        'commission_net_minor',
        'vat_bps',
        'vat_minor',
        'total_gross_minor',
        'currency',
        'status',
        'issued_at',
        'due_at',
        'paid_at',
        'paid_method',
        'provider',
        'provider_ref',
        'number',
        'pdf_path',
        'provider_error',
        'storno_ref',
        'storno_pdf_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'turnover_minor' => 'integer',
            'billable_base_minor' => 'integer',
            'correction_minor' => 'integer',
            'commission_net_minor' => 'integer',
            'vat_bps' => 'integer',
            'vat_minor' => 'integer',
            'total_gross_minor' => 'integer',
            'status' => CommissionInvoiceStatus::class,
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
