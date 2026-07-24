<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionCorrectionType;
use App\Enums\CommissionItemState;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CommissionCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commission credit landing in an open period for a change to an already
 * invoiced one (docs/10 §8.2/§15.5).
 *
 * `commission_delta_minor` is the exact difference between what the closed
 * period's invoice charged and what it would charge for the booking's current
 * reality — computed by replaying §2.3 over that period, never by re-deriving a
 * per-booking share. It is always <= 0: a closed period is never retro-charged.
 *
 * `corrected_amount_minor`/`corrected_state` snapshot the reality this row was
 * credited for, so a later change to the same booking is measured against the
 * already-credited state rather than the original ledger entry.
 *
 * @property int $id
 * @property int $tenant_id
 * @property CommissionCorrectionType $type
 * @property int|null $booking_id
 * @property string $source_period
 * @property string $period
 * @property int|null $corrected_amount_minor
 * @property CommissionItemState|null $corrected_state
 * @property int $commission_delta_minor
 * @property string $currency
 */
class CommissionCorrection extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CommissionCorrectionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'booking_id',
        'source_period',
        'period',
        'corrected_amount_minor',
        'corrected_state',
        'commission_delta_minor',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CommissionCorrectionType::class,
            'booking_id' => 'integer',
            'corrected_amount_minor' => 'integer',
            'corrected_state' => CommissionItemState::class,
            'commission_delta_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
