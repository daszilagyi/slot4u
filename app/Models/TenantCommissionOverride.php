<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantCommissionOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant override of the platform commission settings (docs/10 §5.2).
 * The primary key is tenant_id (one row per tenant). A NULL field inherits
 * from the effective commission_settings.
 *
 * @property int $tenant_id
 * @property int|null $free_threshold_minor
 * @property int|null $rate_bps
 * @property int|null $rate_with_integration_bps
 * @property int|null $monthly_cap_minor
 * @property string|null $note
 * @property int|null $overridden_by
 */
class TenantCommissionOverride extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<TenantCommissionOverrideFactory> */
    use HasFactory;

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'free_threshold_minor',
        'rate_bps',
        'rate_with_integration_bps',
        'monthly_cap_minor',
        'note',
        'overridden_by',
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
            'overridden_by' => 'integer',
        ];
    }
}
