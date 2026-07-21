<?php

declare(strict_types=1);

namespace App\Actions\Commission;

use App\Enums\AuditAction;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;

/**
 * Writes (or clears) a tenant's commission override (docs/10 §5.2, §10).
 *
 * One row per tenant, keyed by `tenant_id`; a NULL field inherits the platform
 * version, so "no override" and "override equal to the default" stay
 * distinguishable. Clearing removes the row entirely rather than nulling every
 * field — the resolver treats both the same, but the trail should say which
 * happened.
 *
 * Runs from the superadmin panel, which has no bound tenant: the target tenant
 * is always explicit, never ambient.
 */
final class SetTenantCommissionOverride
{
    /** @var list<string> */
    private const FIELDS = [
        'free_threshold_minor',
        'rate_bps',
        'rate_with_integration_bps',
        'monthly_cap_minor',
        'note',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  Validated attributes; a NULL (or
     *                                      absent) field means "inherit".
     */
    public function set(Tenant $tenant, array $data): TenantCommissionOverride
    {
        $override = $this->find($tenant);
        $old = $override === null ? null : $this->snapshot($override);

        $creating = $override === null;

        if ($override === null) {
            $override = new TenantCommissionOverride;
            // tenant_id is deliberately not fillable, so the key is set directly
            // rather than mass-assigned from request data.
            $override->tenant_id = $tenant->getKey();
        }

        foreach (self::FIELDS as $field) {
            $override->{$field} = $data[$field] ?? null;
        }

        $override->overridden_by = Auth::id();
        $this->persist($override, $creating);

        $this->audit->record(
            action: AuditAction::CommissionOverrideUpdated,
            auditable: $override,
            oldValues: $old,
            newValues: $this->snapshot($override),
            tenantId: $tenant->getKey(),
        );

        return $override;
    }

    /**
     * Drop the tenant back to the platform settings. Idempotent: clearing a
     * tenant that has no override is a no-op and records nothing.
     */
    public function clear(Tenant $tenant): void
    {
        $override = $this->find($tenant);

        if ($override === null) {
            return;
        }

        $old = $this->snapshot($override);
        $override->delete();

        $this->audit->record(
            action: AuditAction::CommissionOverrideCleared,
            auditable: $override,
            oldValues: $old,
            tenantId: $tenant->getKey(),
        );
    }

    /**
     * Insert without the BelongsToTenant creating hook, which stamps the *bound*
     * tenant over any caller-supplied `tenant_id` — the right default for a
     * tenant-context write, the wrong one here: this action is always told which
     * tenant to price, and ambient context must not redirect that. The superadmin
     * panel binds no tenant today, so nothing changes; it stops a caller from a
     * tenant-bound context (a future API or console entry point) from silently
     * writing the override onto the wrong tenant's bill.
     *
     * Updates keep the normal path: no hook touches `tenant_id` there, and the
     * row is addressed by its explicit key.
     */
    private function persist(TenantCommissionOverride $override, bool $creating): void
    {
        if ($creating) {
            $override->saveQuietly();

            return;
        }

        $override->save();
    }

    /**
     * Keyed lookup with global scopes off: the superadmin panel binds no tenant,
     * and the row is addressed by its explicit primary key either way.
     */
    private function find(Tenant $tenant): ?TenantCommissionOverride
    {
        return TenantCommissionOverride::query()
            ->withoutGlobalScopes()
            ->whereKey($tenant->getKey())
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(TenantCommissionOverride $override): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = $override->{$field};
        }

        return $values;
    }
}
