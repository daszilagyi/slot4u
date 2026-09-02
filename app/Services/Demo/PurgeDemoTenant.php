<?php

declare(strict_types=1);

namespace App\Services\Demo;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\PurgeTenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Erases a demo tenant completely, so `demo:seed --fresh` can rebuild it from
 * nothing (SLO-183, docs/20 §3.2).
 *
 * ## Why this is not {@see PurgeTenant}
 *
 * That one *anonymises* and keeps the skeleton, because a real tenant's
 * bookings carry the turnover slot4u's own commission invoices were computed
 * from, and those must survive for eight years (docs/19 §7). None of that is
 * true here: a demo tenant is never billed (SLO-182), so nothing downstream
 * depends on its rows and a hard delete is both correct and required — a
 * "purged" demo tenant that kept its bookings would come back with yesterday's
 * data still in it.
 *
 * ## ⚠️ Users are deleted first, and this is the whole point of the class
 *
 * `users.tenant_id` is `nullOnDelete`, and in this codebase `tenant_id === null`
 * IS the definition of a platform super-admin
 * ({@see User::isSuperAdmin()}). Deleting the tenant row and letting the
 * database do the rest would therefore not orphan the demo accounts — it would
 * **promote them**. Every nightly `demo:reset` on staging would mint a fresh
 * batch of super-admins whose passwords are published in docs/20 and whose
 * email addresses are derivable from the subdomain.
 *
 * Nothing about that failure is loud: the tenant disappears, the seed succeeds,
 * and the accounts sit in the admin panel looking like staff. So the users go
 * first, explicitly, and a test pins it.
 *
 * Everything else rides the `cascadeOnDelete` foreign keys (29 of the 31
 * `tenant_id` columns carry one), which is deliberate: a table added next month
 * with the usual cascade is handled here without anyone remembering to come
 * back. The two that do not cascade are `users` — above — and `audit_logs`,
 * which has no constraint at all and is cleaned explicitly below.
 */
final class PurgeDemoTenant
{
    public function __invoke(Tenant $tenant): void
    {
        if (! $tenant->is_demo) {
            // The command checks this too. Repeated here because this class
            // hard-deletes a tenant and everything under it: it must not be
            // possible to reach that by calling the service directly.
            throw new RuntimeException(
                "Refusing to purge tenant [{$tenant->slug}]: it is not a demo tenant (SLO-183)."
            );
        }

        $tenantId = $tenant->getKey();

        DB::transaction(function () use ($tenant, $tenantId): void {
            // No foreign key, so nothing would remove these: the audit trail is
            // built to outlive the tenant it describes. For a demo tenant that
            // is only accumulation — a nightly reset would add a fresh set of
            // rows pointing at a tenant id that no longer resolves, and the
            // superadmin audit screen would fill with blanks. Cleared before the
            // users so the rows go while they still name their actor.
            DB::table('audit_logs')->where('tenant_id', $tenantId)->delete();

            // ⚠️ Before the tenant. See the class docblock — any other order
            // here is a super-admin factory. A plain delete: User does not
            // soft-delete, so this is already permanent.
            User::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();

            // Hard, not soft: archiving is what a real tenant gets, and a
            // soft-deleted row would keep the slug and collide with the rebuild.
            $tenant->forceDelete();
        });
    }
}
