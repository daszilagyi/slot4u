<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds automatic tenant isolation to a model: a global scope that filters
 * queries to the current tenant, and auto-fill of `tenant_id` on create.
 *
 * Usage on any tenant-owned model is a single line: `use BelongsToTenant;`
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $tenants = app(TenantManager::class);

            if ($tenants->check()) {
                // Always stamp the current tenant, overriding any caller-supplied
                // value. This prevents controllers from forging ownership by
                // passing an arbitrary tenant_id in fillable request data.
                $model->tenant_id = $tenants->id();
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
