<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TenantDomain;
use App\Models\User;

/**
 * Custom domains are part of the tenant's own configuration, so they ride on
 * `settings.edit` (docs/03 matrix: Tenant Admin) rather than introducing a
 * permission for a single settings panel. Cross-tenant access is impossible:
 * the BelongsToTenant global scope keeps every query inside the current tenant,
 * so a foreign id 404s at route binding. Super-admins pass via Gate::before.
 */
class TenantDomainPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::SettingsEdit->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::SettingsEdit->value);
    }

    public function update(User $user, TenantDomain $domain): bool
    {
        return $user->can(Permission::SettingsEdit->value);
    }

    public function delete(User $user, TenantDomain $domain): bool
    {
        return $user->can(Permission::SettingsEdit->value);
    }
}
