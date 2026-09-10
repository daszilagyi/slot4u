<?php

namespace App\Http\Controllers\Super;

use App\Actions\Tenant\ChangeTenantStatus;
use App\Actions\Tenant\ExtendTrial;
use App\Actions\Tenant\SetTenantFeature;
use App\Actions\Tenant\UpdateTenant;
use App\Enums\AuditAction;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\UpdateTenantRequest;
use App\Models\Tenant;
use App\Models\TenantCommissionOverride;
use App\Services\Audit\AuditLogger;
use App\Services\Commission\ResolveTenantCommissionSettings;
use App\Services\Feature\FeatureResolver;
use App\Services\Privacy\TenantDataExport;
use App\Settings\TenantSignupSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            // Which campaign brought them (SLO-210). Length-capped to the same
            // bound the value object truncates at, so a crafted query string
            // cannot turn a filter into a table scan on a 4 KB literal.
            'source' => ['nullable', 'string', 'max:'.TenantSignupSource::MAX_LENGTH],
        ]);

        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $source = $filters['source'] ?? null;

        $tenants = Tenant::query()
            ->withTrashed()
            ->withCount('users')
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($status, fn ($query) => $query->where('status', $status))
            // An indexed equality on a real column — which is the reason these
            // are columns and not a JSON blob (see the migration).
            ->when($source, fn ($query) => $query->where('signup_utm_source', $source))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tenant $tenant) => $this->summary($tenant));

        return Inertia::render('Super/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search, 'status' => $status, 'source' => $source],
            'statuses' => array_map(fn (TenantStatus $s) => $s->value, TenantStatus::cases()),
            // The breakdown, which is what makes the source "groupable" rather
            // than merely filterable: one aggregate query, not one per source.
            'sources' => $this->signupSourceBreakdown(),
        ]);
    }

    public function show(
        Tenant $tenant,
        FeatureResolver $features,
        ResolveTenantCommissionSettings $resolveSettings,
    ): Response {
        $tenant->loadCount('users');

        // The page carries the commission override editor, so the read is gated
        // by the same policy as the writes rather than relying on the route
        // group alone (a future entry point would inherit this method's rules).
        Gate::authorize('manage', TenantCommissionOverride::class);

        // Resolve all features in two queries (not one per feature).
        $enabled = array_flip($features->enabledCodes($tenant));

        return Inertia::render('Super/Tenants/Show', [
            'tenant' => [
                ...$this->summary($tenant),
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
            ],
            // Commission override editor (SLO-121, docs/10 §5.2/§10): the raw
            // override (null field = inherit) plus what the tenant is actually
            // priced at once the platform version and the override are merged.
            'commissionOverride' => $this->overrideProps($tenant),
            'commissionEffective' => $this->effectiveCommissionProps($tenant, $resolveSettings),
            // Named featureStates (not "features") to avoid shadowing the
            // shared Inertia `features` prop (the tenant's enabled code list).
            'featureStates' => array_map(fn (Feature $feature) => [
                'code' => $feature->value,
                'enabled' => isset($enabled[$feature->value]),
            ], Feature::cases()),
        ]);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant, UpdateTenant $updateTenant): RedirectResponse
    {
        $updateTenant($tenant, $request->validated());

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

    /**
     * Hand an archived tenant its own data back during the grace window
     * (SLO-160, docs/19 §7.4).
     *
     * The tenant-side export lives on its data-protection page, but archiving
     * soft-deletes the tenant and IdentifyTenant 404s the subdomain — so from
     * the moment it would matter most, the tenant cannot reach it. This is the
     * route the archive notice points at: the tenant asks, slot4u produces the
     * file. Same streamed document, same exclusions.
     */
    public function export(Tenant $tenant, TenantDataExport $export, AuditLogger $audit): StreamedResponse
    {
        $audit->record(action: AuditAction::TenantDataExported, auditable: $tenant);

        return response()->streamDownload(
            function () use ($export, $tenant): void {
                foreach ($export->stream($tenant) as $fragment) {
                    echo $fragment;
                }
            },
            $export->filename($tenant),
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, private',
            ],
        );
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
     * The tenant's override row, or null when it inherits the platform settings
     * wholesale. Keyed lookup with global scopes off — the superadmin panel
     * binds no tenant (TenantScope no-ops there, but the intent is explicit).
     *
     * @return array<string, mixed>|null
     */
    private function overrideProps(Tenant $tenant): ?array
    {
        $override = TenantCommissionOverride::query()
            ->withoutGlobalScopes()
            ->whereKey($tenant->getKey())
            ->first();

        if (! $override instanceof TenantCommissionOverride) {
            return null;
        }

        return [
            'free_threshold_minor' => $override->free_threshold_minor,
            'rate_bps' => $override->rate_bps,
            'rate_with_integration_bps' => $override->rate_with_integration_bps,
            'monthly_cap_minor' => $override->monthly_cap_minor,
            'note' => $override->note,
            'updated_at' => $override->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What the tenant is priced at right now (platform version + override), so
     * the editor shows the inherited value behind each blank field. Null while
     * no commission settings version exists yet — the resolver throws rather
     * than inventing a zero threshold (docs/10 §6.4).
     *
     * @return array<string, mixed>|null
     */
    private function effectiveCommissionProps(Tenant $tenant, ResolveTenantCommissionSettings $resolveSettings): ?array
    {
        try {
            $settings = $resolveSettings->resolve($tenant, Carbon::now());
        } catch (RuntimeException) {
            return null;
        }

        return [
            'free_threshold_minor' => $settings->freeThresholdMinor,
            'rate_bps' => $settings->rateBps,
            'rate_with_integration_bps' => $settings->rateWithIntegrationBps,
            'monthly_cap_minor' => $settings->monthlyCapMinor,
            'currency' => $settings->currency,
        ];
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
            // Both the list and the detail page render from here, so the DEMO
            // badge shows up in both from one line (SLO-182).
            'is_demo' => $tenant->is_demo,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
            'users_count' => $tenant->users_count,
            'archived' => $tenant->trashed(),
            'created_at' => $tenant->created_at?->toIso8601String(),
            // Same reasoning as `is_demo` above: the list shows a compact cell
            // and the detail page a full panel, both from this one line.
            'signup' => $this->signupSource($tenant),
        ];
    }

    /**
     * Where this tenant came from, or null when we do not know (SLO-210).
     *
     * Null rather than a "direct" placeholder: most tenants predate the
     * measurement, and calling their absent source "direct" would turn missing
     * data into a claim about it — the same reason the migration backfills
     * nothing.
     *
     * @return array<string, string|null>|null
     */
    private function signupSource(Tenant $tenant): ?array
    {
        $source = TenantSignupSource::fromTenant($tenant);

        if ($source->isEmpty()) {
            return null;
        }

        return [
            'utm_source' => $source->utmSource,
            'utm_medium' => $source->utmMedium,
            'utm_campaign' => $source->utmCampaign,
            'landing_path' => $source->landingPath,
            'landed_at' => $source->landedAt?->toIso8601String(),
        ];
    }

    /**
     * How many tenants each campaign source produced, biggest first.
     *
     * ⚠️ Demo tenants are excluded. They are created by a seeder and rebuilt
     * nightly (SLO-191), so they have no source at all — counting them would
     * inflate the "unknown" bucket with rows that were never an acquisition,
     * and that bucket is the denominator anyone reading this will divide by.
     *
     * Archived tenants ARE counted: a campaign that produced a company which
     * later left still produced it, and hiding churn here would flatter exactly
     * the campaigns that deserve it least.
     *
     * @return list<array{source: string|null, total: int}>
     */
    private function signupSourceBreakdown(): array
    {
        // `toBase()` after the Eloquent scopes: the rows are counts, not
        // tenants, and hydrating a model per group would invite exactly the
        // mistake of reading `$tenant->total` as if it were an attribute.
        return Tenant::query()
            ->withTrashed()
            ->where('is_demo', false)
            ->toBase()
            ->select('signup_utm_source')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('signup_utm_source')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => [
                'source' => is_string($row->signup_utm_source) ? $row->signup_utm_source : null,
                'total' => (int) $row->total,
            ])
            ->all();
    }
}
