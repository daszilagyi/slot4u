<?php

namespace App\Http\Middleware;

use App\Enums\Feature;
use App\Enums\Permission;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Services\Impersonation\Impersonation;
use App\Services\Legal\LegalDocumentRegistry;
use App\Settings\TenantBranding;
use App\Support\CookieConsent;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly FeatureResolver $features,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Memoised enabled-feature codes for the current tenant, so the `features`
     * and `tenant` shared-prop closures resolve them with a single query.
     *
     * @var list<string>|null
     */
    private ?array $enabledFeatures = null;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Lazy: resolved at response render, after IdentifyTenant/SetLocale
            // have set the request locale, so tenant-locale pages get the right
            // catalog. share() itself runs before those route middleware.
            'locale' => fn (): string => app()->getLocale(),
            'translations' => fn (): array => (array) trans('app'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    // Lets the public shell pick the members-area vs admin link and
                    // never offer a customer a staff-only destination (SLO-94).
                    'is_staff' => $user->isStaff(),
                ],
                'permissions' => $this->permissionsFor($user),
            ],
            // Lazily resolved: this middleware runs in the `web` group, before
            // the `identify.tenant` route middleware binds the tenant, so the
            // closure is evaluated at render time when the tenant is available.
            'features' => fn (): array => $this->enabledFeatures(),
            // Current tenant identity for admin branding (name/slug in the
            // sidebar). Null outside tenant context (central/admin domains).
            'tenant' => fn (): ?array => $this->tenantIdentity(),
            // One-off flash status (e.g. password-reset-link sent), already
            // translated by Fortify / the password broker.
            'status' => fn (): ?string => $request->session()->get('status'),
            // Impersonation banner data (SLO-79): present only while a superadmin
            // is inside the tenant they are impersonating, so the layout can show
            // the "impersonation active" bar with a same-origin exit action.
            'impersonation' => fn (): ?array => $this->impersonationState(),
            // The documents in force here (SLO-161). Shared rather than passed
            // per page because six different forms have to show the same tick
            // box, and Fortify's register page has no controller of ours to pass
            // it from. Lazy for the same reason as `features`: the tenant is
            // bound by route middleware that runs after this one.
            'legal' => fn (): array => $this->legalDocuments(),
            // The visitor's cookie decision (SLO-165). Read from the request, so
            // the banner's visibility is settled before the first byte goes out
            // — a client-side decision flashes the banner on every page.
            'consent' => fn (): array => CookieConsent::fromRequest($request)->toArray(),
        ];
    }

    /**
     * The documents a visitor on this host may be asked to accept, with a link
     * each, and the id set the form must submit back so a version published
     * mid-form can be caught (SLO-161).
     *
     * @return array{documents: list<array{id: int, type: string, version: string, title: string, href: string}>, ids: list<int>}
     */
    private function legalDocuments(): array
    {
        $tenant = app(TenantManager::class)->current();
        $documents = app(LegalDocumentRegistry::class)->currentFor($tenant);

        return [
            'documents' => $documents->values()->map(fn (LegalDocument $document): array => [
                'id' => $document->getKey(),
                'type' => $document->type->value,
                'version' => $document->version,
                'title' => $document->title,
                // Always our own URL, even for a linked document: the redirect
                // keeps one shape for the form to render and one place where
                // "which document did they open" could ever be answered.
                'href' => '/legal/'.$document->getKey(),
            ])->all(),
            'ids' => $documents->map->getKey()->values()->all(),
        ];
    }

    /**
     * Banner state for an active impersonation, or null. Scoped to the tenant
     * actually being impersonated so the bar never leaks onto the admin panel
     * or an unrelated tenant.
     *
     * @return array{tenant: array{id: int, name: string}, stopUrl: string}|null
     */
    private function impersonationState(): ?array
    {
        $tenantId = $this->impersonation->tenantId();
        $current = $this->tenants->current();

        if ($tenantId === null || $current === null || $current->getKey() !== $tenantId) {
            return null;
        }

        return [
            'tenant' => [
                'id' => $tenantId,
                'name' => (string) $this->impersonation->tenantName(),
            ],
            // Same-origin (this tenant subdomain); see routes/tenant.php.
            'stopUrl' => '/impersonation',
        ];
    }

    /**
     * Enabled feature codes for the current tenant, so the frontend can gate UI
     * on them (mirrors the server-side EnsureFeatureEnabled middleware). Empty
     * outside tenant context (central/admin domains). Memoised for the request so
     * the `features` and `tenant` shared props share one tenant_features query.
     *
     * @return list<string>
     */
    private function enabledFeatures(): array
    {
        if ($this->enabledFeatures !== null) {
            return $this->enabledFeatures;
        }

        $tenant = $this->tenants->current();

        return $this->enabledFeatures = $tenant === null
            ? []
            : $this->features->enabledCodes($tenant);
    }

    /**
     * Minimal current-tenant identity for admin branding, or null off-tenant.
     * The tenant's logo + primary colour (SLO-21) are gated behind
     * `feature_branding` (docs/03): when the feature is off the brand falls back
     * to no logo + the default colour, matching the cover gate in HomeController
     * and the locked branding editor in SettingsController (SLO-90).
     *
     * @return array{name: string, slug: string, logo_url: string|null, primary_color: string, primary_foreground: string}|null
     */
    private function tenantIdentity(): ?array
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            return null;
        }

        $branding = TenantBranding::fromArray($tenant->branding);
        // Reuse the memoised feature list rather than a second tenant_features
        // query — the `features` shared prop already resolves it this request.
        $branded = in_array(Feature::Branding->value, $this->enabledFeatures(), true);

        $primaryColor = $branded
            ? $branding->primaryColor
            : TenantBranding::DEFAULT_PRIMARY_COLOR;

        return [
            // Numeric id for the private realtime channel (`tenant.{id}.bookings`,
            // SLO-118); name/slug/branding drive the sidebar identity.
            'id' => $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'logo_url' => $branded ? $branding->logoUrl() : null,
            'primary_color' => $primaryColor,
            // Both halves of the token, because the public shell overrides both:
            // `--primary` alone would leave the near-white default sitting on a
            // tenant who picked a pale brand colour.
            'primary_foreground' => TenantBranding::readableForeground($primaryColor),
        ];
    }

    /**
     * The permission codes the frontend may gate UI on. Super-admins receive
     * every code (they bypass checks server-side via Gate::before).
     *
     * @return list<string>
     */
    private function permissionsFor(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return Permission::values();
        }

        return $user->getAllPermissions()->pluck('name')->values()->all();
    }
}
