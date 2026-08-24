<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Introduces the `schedule.manage_all` permission (SLO-177) and backfills it
 * onto the roles that already behaved as if they had it.
 *
 * Before this, `schedule.manage` was all-or-nothing and the employee role held
 * it in full — so an employee could rewrite any colleague's (or any room's)
 * working hours, while the docs/03 matrix has always said "saját". The scope is
 * now a permission code, following the SLO-142 precedent for `customer.view_all`:
 * a role NAME test can never be satisfied by a tenant's own custom role.
 *
 * ⚠️ The backfill is deliberately asymmetric with the seeder. `tenant-admin` and
 * `manager` are granted the new code, so their behaviour after deploy is exactly
 * what it was before it. `employee` is NOT — that is the whole point of the
 * change, and the one role whose effective reach deliberately narrows here.
 *
 * A tenant's own custom roles are left alone as well: they were unrestricted
 * before this (there was nothing to be restricted by), and they narrow to the
 * holder's own staff now. That is the intended reading of the matrix, and the
 * tenant admin can widen any of them in the role editor — the code shows up
 * there like every other one.
 *
 * The codes are written as literals rather than enum references on purpose — a
 * migration records what happened at a point in time and must not change meaning
 * if the enum is later renamed.
 */
return new class extends Migration
{
    private const PERMISSION = 'schedule.manage_all';

    /** The built-in roles that managed the whole tenant's schedule before SLO-177. */
    private const UNRESTRICTED_ROLES = ['tenant-admin', 'manager'];

    public function up(): void
    {
        $permissionId = $this->permissionId();

        $rows = DB::table('roles')
            ->whereIn('name', self::UNRESTRICTED_ROLES)
            ->where('guard_name', 'web')
            ->pluck('id')
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
