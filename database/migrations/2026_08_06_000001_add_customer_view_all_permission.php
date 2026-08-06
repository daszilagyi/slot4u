<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Introduces the `customer.view_all` permission (SLO-142) and backfills it onto
 * the roles that already behaved as if they had it.
 *
 * Before this, "sees every customer vs. only their own" was decided by the
 * tenant-admin / manager role NAMES in CustomerVisibility. A tenant-defined
 * custom role could never satisfy a name test, so the scope became a permission
 * code. Existing tenants must not notice: every tenant-admin and manager role
 * already in the database is granted the new code here, so the visible
 * behaviour after deploy is identical to before it.
 *
 * The codes are written as literals rather than enum references on purpose — a
 * migration records what happened at a point in time and must not change
 * meaning if the enum is later renamed.
 */
return new class extends Migration
{
    private const PERMISSION = 'customer.view_all';

    /** The roles that were unrestricted by name before SLO-142. */
    private const UNRESTRICTED_ROLES = ['tenant-admin', 'manager'];

    public function up(): void
    {
        $permissionId = $this->permissionId();

        $roleIds = DB::table('roles')
            ->whereIn('name', self::UNRESTRICTED_ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        $rows = $roleIds
            ->map(fn ($roleId): array => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ])
            ->all();

        if ($rows !== []) {
            // insertOrIgnore: the permission may already be attached on an
            // environment seeded after the enum case landed.
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($permissionId !== null) {
            DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The permission row, created if the seeder has not run yet. */
    private function permissionId(): int
    {
        $existing = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
