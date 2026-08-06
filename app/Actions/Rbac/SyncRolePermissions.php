<?php

declare(strict_types=1);

namespace App\Actions\Rbac;

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Models\Tenant;
use App\Policies\RolePolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Rbac\PermissionCatalog;
use App\Services\Rbac\TenantTeam;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * Writes a tenant role's permission grant (SLO-141): both the custom set the
 * role editor submits and the reset back to the seeded default (docs/03 matrix).
 *
 * Which roles may be written at all is the {@see RolePolicy}'s
 * call, and which codes may be submitted is the FormRequest's; this action owns
 * what happens once both said yes — the two invariants that must hold no matter
 * which entry point calls it:
 *
 * 1. **Admin-reserved codes never land on another role.** The request already
 *    rejects them, but the filter is repeated here so a future caller (the
 *    Phase 2 API) cannot route around it.
 * 2. **A grant the editor could not show is preserved, not dropped.** A code
 *    whose feature is currently off is rendered locked, so the form never sends
 *    it back; without this the admin would silently revoke it by toggling
 *    something unrelated. Locked grants therefore survive every edit and can
 *    only change by the feature coming back on.
 *
 * The spatie team context is set explicitly rather than inherited from the
 * request, so the action is correct off the tenant host too, and the permission
 * cache is flushed at the end — without it the change would only take effect on
 * the next cache expiry, and the "customization applies immediately" acceptance
 * criterion would be a coin flip.
 */
final class SyncRolePermissions
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PermissionCatalog $catalog,
        private readonly TenantTeam $team,
    ) {}

    /**
     * @param  list<string>  $permissions  The codes the editor submitted.
     */
    public function update(Tenant $tenant, RoleModel $role, array $permissions): void
    {
        $grantable = $this->catalog->grantableCodes($tenant);

        $this->apply(
            $tenant,
            $role,
            array_values(array_intersect($permissions, $grantable)),
            AuditAction::RolePermissionsUpdated,
        );
    }

    /**
     * Restores the role's seeded grant from {@see RoleEnum::permissions()} — the
     * documented default, not a filtered version of it. A default code whose
     * feature is off stays granted and simply renders locked; silently trimming
     * it would make "reset to default" mean something different per tenant.
     */
    public function reset(Tenant $tenant, RoleModel $role): void
    {
        $defaults = array_map(
            static fn ($permission): string => $permission->value,
            RoleEnum::from($role->name)->permissions(),
        );

        $this->apply($tenant, $role, $defaults, AuditAction::RolePermissionsReset);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function apply(Tenant $tenant, RoleModel $role, array $permissions, AuditAction $action): void
    {
        $this->team->run($tenant, function () use ($tenant, $role, $permissions, $action): void {
            $before = $this->currentCodes($role);

            // Codes the editor could not offer keep their current state (see the
            // class docblock), and an admin-reserved code never survives.
            $locked = array_keys($this->catalog->featureLocked($tenant));
            $final = array_values(array_unique(array_merge(
                array_filter($permissions, static fn (string $code): bool => ! self::isAdminReserved($code)),
                array_intersect($before, $locked),
            )));

            sort($final);

            $role->syncPermissions($final);

            $this->audit->record(
                action: $action,
                auditable: $role,
                oldValues: ['role' => $role->name, 'permissions' => $before],
                newValues: ['role' => $role->name, 'permissions' => $final],
                tenantId: $tenant->getKey(),
            );
        });

        $this->team->flush();
    }

    /**
     * @return list<string>
     */
    private function currentCodes(RoleModel $role): array
    {
        $codes = $role->permissions()->pluck('name')->all();
        sort($codes);

        /** @var list<string> */
        return $codes;
    }

    private static function isAdminReserved(string $code): bool
    {
        $permission = Permission::tryFrom($code);

        return $permission !== null && $permission->isAdminReserved();
    }
}
