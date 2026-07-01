<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Services\Impersonation\Impersonation;
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
                ],
                'permissions' => $this->permissionsFor($user),
            ],
            // Lazily resolved: this middleware runs in the `web` group, before
            // the `identify.tenant` route middleware binds the tenant, so the
            // closure is evaluated at render time when the tenant is available.
            'features' => fn (): array => $this->enabledFeatures(),
            // One-off flash status (e.g. password-reset-link sent), already
            // translated by Fortify / the password broker.
            'status' => fn (): ?string => $request->session()->get('status'),
            // Impersonation banner data (SLO-79): present only while a superadmin
            // is inside the tenant they are impersonating, so the layout can show
            // the "impersonation active" bar with a same-origin exit action.
            'impersonation' => fn (): ?array => $this->impersonationState(),
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
     * outside tenant context (central/admin domains).
     *
     * @return list<string>
     */
    private function enabledFeatures(): array
    {
        $tenant = $this->tenants->current();

        return $tenant === null ? [] : $this->features->enabledCodes($tenant);
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
