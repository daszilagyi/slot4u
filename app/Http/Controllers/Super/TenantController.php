<?php

namespace App\Http\Controllers\Super;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Actions\Tenant\ExtendTrial;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\UpdateTenantRequest;
use App\Models\Tenant;
use App\Services\Feature\FeatureResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Superadmin tenant management (SLO-77). The whole controller lives behind
 * auth + ensure.superadmin (routes/admin.php); business logic is delegated to
 * Action classes so SLO-78 can wrap them with audit logging.
 */
class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(TenantStatus::class)],
        ]);

        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;

        $tenants = Tenant::query()
            ->withTrashed()
            ->withCount('users')
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => $this->summary($tenant));

        return Inertia::render('Super/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => array_map(fn (TenantStatus $s) => $s->value, TenantStatus::cases()),
        ]);
    }

    public function show(Tenant $tenant, FeatureResolver $features): Response
    {
        $tenant->loadCount('users');

        // Resolve all features in two queries (not one per feature).
        $enabled = array_flip($features->enabledCodes($tenant));

        return Inertia::render('Super/Tenants/Show', [
            'tenant' => [
                ...$this->summary($tenant),
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
            ],
            // Named featureStates (not "features") to avoid shadowing the
            // shared Inertia `features` prop (the tenant's enabled code list).
            'featureStates' => array_map(fn (Feature $feature) => [
                'code' => $feature->value,
                'enabled' => isset($enabled[$feature->value]),
            ], Feature::cases()),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update($request->validated());

        return back();
    }

    public function suspend(Tenant $tenant, ChangeTenantStatus $changeStatus): RedirectResponse
    {
        $changeStatus($tenant, TenantStatus::Suspended);

        return back();
    }

    public function activate(Tenant $tenant, ChangeTenantStatus $changeStatus): RedirectResponse
    {
        $changeStatus($tenant, TenantStatus::Active);

        return back();
    }

    public function archive(Tenant $tenant, ChangeTenantStatus $changeStatus): RedirectResponse
    {
        $changeStatus($tenant, TenantStatus::Archived);

        return back();
    }

    public function extendTrial(Tenant $tenant, ExtendTrial $extendTrial): RedirectResponse
    {
        $extendTrial($tenant);

        return back();
    }

    public function toggleFeature(Request $request, Tenant $tenant, SetTenantFeature $setFeature): RedirectResponse
    {
        $data = $request->validate([
            'feature' => ['required', Rule::enum(Feature::class)],
            'enabled' => ['required', 'boolean'],
        ]);

        $setFeature(
            $tenant,
            Feature::from($data['feature']),
            $data['enabled'],
            $request->user()->id,
        );

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status->value,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'users_count' => $tenant->users_count,
            'archived' => $tenant->trashed(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
