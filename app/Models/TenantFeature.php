<?php

namespace App\Models;

use App\Enums\Feature;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-scoped feature override (superadmin enables/disables per tenant).
 * Overrides the plan default from `plan_features`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property Feature $feature_code
 * @property bool $enabled
 * @property int|null $overridden_by
 */
class TenantFeature extends Model
{
    /** @use HasFactory<TenantFeatureFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'feature_code',
        'enabled',
        'overridden_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature_code' => Feature::class,
            'enabled' => 'boolean',
        ];
    }
}
