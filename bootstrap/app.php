<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $central = config('tenancy.central_domain');
            $adminSubdomain = config('tenancy.admin_subdomain');

            // Register admin BEFORE tenant so `admin.{central}` is not swallowed
            // by the wildcard `{tenant}.{central}` pattern.
            Route::middleware('web')
                ->domain($adminSubdomain.'.'.$central)
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')
                ->domain('{tenant}.'.$central)
                ->group(base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // SetLocale before Inertia sharing so the `locale`/`translations`
            // props reflect the resolved locale. On tenant domains IdentifyTenant
            // (route middleware) runs after and overrides with the tenant locale.
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'identify.tenant' => IdentifyTenant::class,
            'ensure.tenant.active' => EnsureTenantActive::class,
            'ensure.user.tenant' => EnsureUserBelongsToTenant::class,
            'ensure.superadmin' => EnsureSuperAdmin::class,
            'ensure.feature' => EnsureFeatureEnabled::class,
            // `can:` is built in; these add spatie's role/permission gates.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Pin the tenant chain ahead of SubstituteBindings so route-model
        // binding of tenant-owned models (BelongsToTenant) is already scoped to
        // the resolved tenant — otherwise a cross-tenant id would resolve and
        // leak (docs/01 chain: IdentifyTenant → EnsureTenantActive → [auth] →
        // EnsureUserBelongsToTenant → SubstituteBindings → can).
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            IdentifyTenant::class,
            EnsureTenantActive::class,
            AuthenticatesRequests::class,
            EnsureUserBelongsToTenant::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
