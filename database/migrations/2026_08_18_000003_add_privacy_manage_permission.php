<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Introduces the `privacy.manage` permission (SLO-159) and grants it to the
 * existing tenant-admin roles.
 *
 * Only tenant-admin: the code is new, so nothing behaved as if it had it, and
 * handing a fresh destructive ability to manager/employee roles a tenant
 * configured months ago would be a silent privilege grant. The tenant-admin
 * role already carries every code by definition ({@see Role}), so
 * backfilling it there keeps the seeded matrix and the database in step. Any
 * wider delegation is the tenant's own decision in the role editor.
 *
 * The code is a literal, not an enum reference: a migration records what
 * happened at a point in time and must not shift meaning if the enum is renamed.
 */
return new class extends Migration
{
    private const PERMISSION = 'privacy.manage';

    public function up(): void
    {
        $permissionId = $this->permissionId();

        $roleIds = DB::table('roles')
            ->where('name', 'tenant-admin')
            ->where('guard_name', 'web')
            ->pluck('id');

        $rows = $roleIds
            ->map(fn ($roleId): array => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ])
            ->all();

        if ($rows !== []) {
            // insertOrIgnore: on an environment seeded after the enum case
            // landed the grant is already there.
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
