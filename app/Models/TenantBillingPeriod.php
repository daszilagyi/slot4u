<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingPeriodStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantBillingPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Monthly commission aggregate per tenant (docs/10 §5.4) — a derived cache of
 * the commission ledger.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $period
 * @property int $turnover_minor
 * @property int $commission_minor
 * @property bool $cap_reached
 * @property BillingPeriodStatus $status
 * @property int|null $invoice_id
 * @property Carbon|null $recomputed_at
 */
class TenantBillingPeriod extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantBillingPeriodFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'period',
        'turnover_minor',
        'commission_minor',
        'cap_reached',
        'status',
        'invoice_id',
        'recomputed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'turnover_minor' => 'integer',
            'commission_minor' => 'integer',
            'cap_reached' => 'boolean',
            'status' => BillingPeriodStatus::class,
            'invoice_id' => 'integer',
            'recomputed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CommissionInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CommissionInvoice::class);
    }
}
