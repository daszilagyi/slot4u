<?php

namespace App\Http\Controllers\Super;

use App\Actions\Commission\CreateCommissionSettingVersion;
use App\Actions\Commission\SetTenantCommissionOverride;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\StoreCommissionSettingRequest;
use App\Http\Requests\Super\UpdateTenantCommissionOverrideRequest;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin commission configuration (J8a, docs/10 §10): the platform pricing
 * versions and the per-tenant overrides layered on top of them.
 *
 * Lives behind auth + ensure.superadmin (routes/admin.php). Versions are
 * immutable — the editor publishes a new one rather than editing a row — so the
 * history stays a faithful record of what priced any given period (§5.1, §12/16).
 */
class CommissionController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', CommissionSetting::class);

        $versions = CommissionSetting::query()
            // Eager-loaded: the history renders the author per row (DoD: no N+1).
            ->with('creator:id,name')
            // Same ordering the resolver uses to pick the effective version
            // (§6.4), so "first row" here means what it means there.
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Super/Commission/Index', [
            'versions' => $versions->map(fn (CommissionSetting $s): array => $this->versionRow($s))->all(),
            'effective_id' => $this->effectiveVersion($versions)?->id,
        ]);
    }

    public function store(StoreCommissionSettingRequest $request, CreateCommissionSettingVersion $create): RedirectResponse
    {
        Gate::authorize('create', CommissionSetting::class);

        $create($request->validated());

        return back();
    }

    public function updateOverride(
        UpdateTenantCommissionOverrideRequest $request,
        Tenant $tenant,
        SetTenantCommissionOverride $setOverride,
    ): RedirectResponse {
        Gate::authorize('manage', TenantCommissionOverride::class);

        $setOverride->set($tenant, $request->validated());

        return back();
    }

    public function clearOverride(Tenant $tenant, SetTenantCommissionOverride $setOverride): RedirectResponse
    {
        Gate::authorize('manage', TenantCommissionOverride::class);

        $setOverride->clear($tenant);

        return back();
    }

    /**
     * The version in force right now: the newest one whose `effective_from` has
     * passed. A version dated in the future is published but not yet pricing
     * anything, and the list may hold several — mirrors the resolver's pick.
     *
     * @param  Collection<int, CommissionSetting>  $versions
     */
    private function effectiveVersion(Collection $versions): ?CommissionSetting
    {
        // The collection is already ordered newest-first, so the first version
        // that has taken effect is the effective one. Done in memory: the whole
        // (small) history is loaded anyway, and a second query could disagree
        // with the list under a concurrent insert.
        return $versions->first(fn (CommissionSetting $s): bool => ! $s->effective_from->isFuture());
    }

    /**
     * @return array<string, mixed>
     */
    private function versionRow(CommissionSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'free_threshold_minor' => $setting->free_threshold_minor,
            'rate_bps' => $setting->rate_bps,
            'rate_with_integration_bps' => $setting->rate_with_integration_bps,
            'monthly_cap_minor' => $setting->monthly_cap_minor,
            'currency' => $setting->currency,
            'effective_from' => $setting->effective_from->toIso8601String(),
            'scheduled' => $setting->effective_from->isFuture(),
            'creator' => $setting->creator === null ? null : [
                'id' => $setting->creator->id,
                'name' => $setting->creator->name,
            ],
            'created_at' => $setting->created_at?->toIso8601String(),
        ];
    }
}
