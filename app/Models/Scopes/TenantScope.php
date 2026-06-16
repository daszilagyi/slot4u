<?php

namespace App\Models\Scopes;

use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains every query on a BelongsToTenant model to the current tenant.
 *
 * No-op when no tenant is bound (migrations, seeders, superadmin, console,
 * queue, cross-tenant aggregation) so those contexts see all rows.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenants = app(TenantManager::class);

        if (! $tenants->check()) {
            return;
        }

        $builder->where(
            $model->qualifyColumn('tenant_id'),
            $tenants->id(),
        );
    }
}
