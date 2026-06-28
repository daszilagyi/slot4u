<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Feature\FeatureResolver;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly FeatureResolver $features,
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
            'locale' => app()->getLocale(),
            'translations' => (array) trans('app'),
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
