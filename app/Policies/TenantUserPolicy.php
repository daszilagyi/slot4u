<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

/**
 * Who may reshape another user's roles and direct permissions (SLO-142, docs/03
 * `role.manage` = Tenant Admin). Registered explicitly in AppServiceProvider —
 * {@see Customer} extends User and keeps its own auto-discovered
 * CustomerPolicy, because Laravel resolves a policy by naming convention before
 * it falls back to a parent class registration.
 *
 * Super-admins pass via the Gate::before hook; an impersonating super-admin
 * holds no tenant role, so the "not yourself" rule cannot accidentally catch
 * them.
 */
class TenantUserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RoleManage->value);
    }

    /**
     * Editing a user's grant.
     *
     * The actor may never edit themselves. This is the per-user form of the same
     * rule the role editor enforces: a tenant admin who could edit their own
     * assignment could hand themselves a role, or strip a colleague's safeguard
     * by first widening their own. Someone else holding `role.manage` can still
     * edit them, so a tenant is never stuck with a grant nobody may change.
     *
     * Tenant membership is not checked here — a user from another tenant 404s on
     * lookup (docs/01: a cross-tenant probe must not confirm the row exists),
     * which is a stronger answer than 403.
     */
    public function update(User $user, User $target): bool
    {
        if (! $user->can(Permission::RoleManage->value)) {
            return false;
        }

        return $user->getKey() !== $target->getKey();
    }
}
