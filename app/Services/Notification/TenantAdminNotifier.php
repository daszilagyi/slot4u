<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sends a notification to a tenant's administrators — the billing contacts for
 * commission invoices and dunning (docs/10 §6.5/6.6). There is no dedicated
 * billing-contact field, so the tenant-admin role is the recipient set.
 *
 * Works off the request cycle (scheduled commands, listeners): it pins the spatie
 * team scope to the tenant while resolving admins, then restores the previous
 * scope so it never leaks into the surrounding context.
 */
final class TenantAdminNotifier
{
    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /**
     * Notify every admin of the tenant. Returns how many recipients were mailed.
     */
    public function notify(Tenant $tenant, Notification $notification): int
    {
        $admins = $this->admins($tenant);

        foreach ($admins as $admin) {
            $admin->notify($notification);
        }

        return $admins->count();
    }

    /**
     * The tenant's admin users, resolved with the spatie team scope pinned to the
     * tenant (restored afterwards).
     *
     * @return Collection<int, User>
     */
    public function admins(Tenant $tenant): Collection
    {
        $previousTeamId = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->getKey());

        try {
            return User::query()
                ->where('tenant_id', $tenant->getKey())
                ->role(Role::TenantAdmin->value)
                ->get();
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
