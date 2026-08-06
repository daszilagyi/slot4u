<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Enums\AuditAction;
use App\Enums\Role as RoleEnum;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\PermissionCatalog;
use App\Services\Rbac\TenantTeam;

/**
 * Writes one staff user's role assignment and per-user permission overrides
 * (SLO-142) — the individual half of "minden szolgáltatást szabadon lehessen
 * engedélyezni userenként vagy csoportonként".
 *
 * Roles and direct permissions are written together because they are one
 * decision: a direct grant only makes sense relative to what the roles already
 * give, and applying them in two requests would leave a window where the user
 * holds a role's grant plus overrides meant for a different role.
 *
 * Two sets are deliberately preserved rather than replaced, both for the same
 * reason as in {@see SyncRolePermissions}: the form cannot send back what it may
 * not show, so anything it may not show must survive the save.
 *
 * 1. **The `customer` role.** A user can be both staff and a customer of the
 *    same tenant (they book for themselves). The editor manages staff roles
 *    only, so a customer role the user holds is carried over — otherwise saving
 *    an unrelated toggle would quietly evict them from the members area.
 * 2. **Direct grants of feature-locked codes.** A code whose feature is off is
 *    rendered locked, so it never comes back in the payload.
 *
 * Admin-reserved codes (`billing.*`, `role.manage`) never survive: the catalog
 * excludes them, so intersecting the payload with it drops them here as well as
 * in the FormRequest. As a direct permission they would bypass the role editor's
 * guardrail entirely, which is the whole point of that guardrail.
 */
final class SyncUserRbac
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionCatalog $catalog,
        private readonly TenantTeam $team,
    ) {}

    /**
     * @param  list<string>  $roles  Staff role names the user should end up with.
     * @param  list<string>  $permissions  Direct permission codes on top of the roles.
     */
    public function __invoke(Tenant $tenant, User $user, array $roles, array $permissions): void
    {
        $grantable = $this->catalog->grantableCodes($tenant);
        $locked = array_keys($this->catalog->featureLocked($tenant));

        $this->team->run($tenant, function () use ($tenant, $user, $roles, $permissions, $grantable, $locked): void {
            $beforeRoles = $this->currentRoles($user);
            $beforePermissions = $this->currentPermissions($user);

            // The customer role is not the editor's to remove (see the class
            // docblock); every other role the payload omits is dropped.
            $finalRoles = array_values(array_unique(array_merge(
                array_filter($roles, static fn (string $name): bool => RoleEnum::isStaffRoleName($name)),
                array_intersect($beforeRoles, [RoleEnum::Customer->value]),
            )));
            sort($finalRoles);

            $finalPermissions = array_values(array_unique(array_merge(
                array_intersect($permissions, $grantable),
                array_intersect($beforePermissions, $locked),
            )));
            sort($finalPermissions);

            $user->syncRoles($finalRoles);
            $user->syncPermissions($finalPermissions);

            $this->audit->record(
                action: AuditAction::UserRbacUpdated,
                auditable: $user,
                oldValues: ['roles' => $beforeRoles, 'permissions' => $beforePermissions],
                newValues: ['roles' => $finalRoles, 'permissions' => $finalPermissions],
                tenantId: $tenant->getKey(),
            );
        });

        $this->team->flush();
    }

    /**
     * @return list<string>
     */
    private function currentRoles(User $user): array
    {
        $names = $user->roles()->pluck('name')->all();
        sort($names);

        /** @var list<string> */
        return $names;
    }

    /**
     * The user's DIRECT permissions only — the ones this editor owns. Codes that
     * arrive through a role are not repeated here, so a role change never leaves
     * a fossilised copy of an old grant pinned to the user.
     *
     * @return list<string>
     */
    private function currentPermissions(User $user): array
    {
        $codes = $user->permissions()->pluck('name')->all();
        sort($codes);

        /** @var list<string> */
        return $codes;
    }
}
