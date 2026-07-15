<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionItemState;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingCommissionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single commission ledger entry (docs/10 §5.3) — the source of truth from
 * which {@see TenantBillingPeriod} aggregates are recomputed. One row per
 * commission-bearing booking (unique `booking_id`); `amount_minor` and `rate_bps`
 * are snapshots frozen when the booking became billable (§2.4).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $booking_id
 * @property string $period
 * @property int $amount_minor
 * @property int $rate_bps
 * @property Carbon $realized_at
 * @property CommissionItemState $state
 * @property int $settings_id
 * @property string $currency
 */
class BookingCommissionItem extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingCommissionItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'booking_id',
        'period',
        'amount_minor',
        'rate_bps',
        'realized_at',
        'state',
        'settings_id',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'rate_bps' => 'integer',
            'realized_at' => 'datetime',
            'state' => CommissionItemState::class,
            'settings_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<CommissionSetting, $this>
     */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(CommissionSetting::class, 'settings_id');
    }
}
