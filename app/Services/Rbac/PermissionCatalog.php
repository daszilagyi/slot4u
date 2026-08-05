<?php

declare(strict_types=1);

namespace App\Services\Rbac;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Enums\PermissionGroup;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;

/**
 * What a tenant may actually hand out in its role editor (SLO-141).
 *
 * Two filters narrow the full {@see Permission} set, and both are enforced here
 * rather than in the UI, because the FormRequest validates against this same
 * list — hiding a checkbox is a courtesy, refusing the code is the guarantee:
 *
 * 1. **Admin-reserved codes** ({@see Permission::isAdminReserved()}) never leave
 *    the tenant-admin role.
 * 2. **Feature-dependent codes** ({@see Permission::requiredFeature()}) are only
 *    offerable while the tenant has that flag on. Granting one with the feature
 *    off would be a permission whose every route answers 403 from the feature
 *    gate anyway — an entry in the editor that promises something it cannot
 *    deliver.
 *
 * Turning a feature off later does NOT strip the code from a role: the grant is
 * the tenant's stated intent, and the feature gate already blocks the route. It
 * simply stops being offerable, and re-enabling the feature makes the existing
 * grant live again without the admin having to remember it.
 */
final class PermissionCatalog
{
    public function __construct(
        private readonly FeatureResolver $features,
    ) {}

    /**
     * Every code the tenant may grant right now.
     *
     * @return list<Permission>
     */
    public function grantable(Tenant $tenant): array
    {
        $enabled = $this->features->enabledCodes($tenant);

        return array_values(array_filter(
            Permission::cases(),
            function (Permission $permission) use ($enabled): bool {
                if ($permission->isAdminReserved()) {
                    return false;
                }

                $feature = $permission->requiredFeature();

                return $feature === null || in_array($feature->value, $enabled, true);
            },
        ));
    }

    /**
     * The grantable codes as plain strings — the validation allow-list.
     *
     * @return list<string>
     */
    public function grantableCodes(Tenant $tenant): array
    {
        return array_map(
            static fn (Permission $permission): string => $permission->value,
            $this->grantable($tenant),
        );
    }

    /**
     * The codes that exist for this tenant but cannot be toggled right now,
     * mapped to the flag to blame.
     *
     * They are *shown* — locked, with the reason — rather than hidden: a
     * silently missing permission reads as "slot4u forgot it", a locked one as
     * "ask for the feature". The tenant admin cannot flip the flag itself (that
     * is a superadmin override), so the explanation is the whole value of the
     * row.
     *
     * @return array<string, string> Permission code => feature code.
     */
    public function featureLocked(Tenant $tenant): array
    {
        $enabled = $this->features->enabledCodes($tenant);
        $locked = [];

        foreach (Permission::cases() as $permission) {
            if ($permission->isAdminReserved()) {
                continue;
            }

            $feature = $permission->requiredFeature();

            if ($feature instanceof Feature && ! in_array($feature->value, $enabled, true)) {
                $locked[$permission->value] = $feature->value;
            }
        }

        return $locked;
    }

    /**
     * The catalog shaped for the editor: groups in display order, each code
     * carrying the feature that locks it (null = freely toggleable).
     *
     * Admin-reserved codes never appear at all — they are not a tenant-editable
     * concept, so showing them locked would only invite the question "how do I
     * unlock this?", to which the answer is "you cannot, ever".
     *
     * @return list<array{key: string, permissions: list<array{code: string, locked_by: string|null}>}>
     */
    public function groups(Tenant $tenant): array
    {
        $locked = $this->featureLocked($tenant);
        $groups = [];

        foreach (PermissionGroup::ordered() as $group) {
            $codes = [];

            foreach (Permission::cases() as $permission) {
                if ($permission->isAdminReserved() || $permission->group() !== $group) {
                    continue;
                }

                $codes[] = [
                    'code' => $permission->value,
                    'locked_by' => $locked[$permission->value] ?? null,
                ];
            }

            if ($codes !== []) {
                $groups[] = ['key' => $group->value, 'permissions' => $codes];
            }
        }

        return $groups;
    }
}
