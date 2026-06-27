<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommissionSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Platform-level, versioned commission configuration (docs/10 §5.1).
 * NOT tenant-owned — this is the global default. Rows are immutable; a new
 * configuration is a new row, never an update.
 *
 * @property int $id
 * @property int $free_threshold_minor
 * @property int $rate_bps
 * @property int $rate_with_integration_bps
 * @property int|null $monthly_cap_minor
 * @property string $currency
 * @property Carbon $effective_from
 * @property int|null $created_by
 */
class CommissionSetting extends Model
{
    /** @use HasFactory<CommissionSettingFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'free_threshold_minor',
        'rate_bps',
        'rate_with_integration_bps',
        'monthly_cap_minor',
        'currency',
        'effective_from',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'free_threshold_minor' => 'integer',
            'rate_bps' => 'integer',
            'rate_with_integration_bps' => 'integer',
            'monthly_cap_minor' => 'integer',
            'effective_from' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
