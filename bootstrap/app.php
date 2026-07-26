<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\EnsureUserIsCustomer;
use App\Http\Middleware\EnsureUserIsStaff;
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
        channels: __DIR__.'/../routes/channels.php',
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
        // Prod runs behind Cloudflare (SLO-125). The shared-hosting Apache
        // already restores the visitor IP into REMOTE_ADDR (mod_cloudflare), so
        // this is a framework-level safety net that keeps client IP (rate limit,
        // audit) and X-Forwarded-Proto (HTTPS scheme) correct even if the edge
        // stack changes — trusting only Cloudflare's published ranges, never `*`,
        // so a direct-to-origin request cannot spoof a forwarded IP.
        // Ranges: https://www.cloudflare.com/ips/ (dev/CI have no proxy → no-op).
        $middleware->trustProxies(at: [
            // IPv4 — https://www.cloudflare.com/ips-v4
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22',
            '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22',
            '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // IPv6 — https://www.cloudflare.com/ips-v6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32',
            '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        ]);

        $middleware->web(append: [
            // SetLocale before Inertia sharing so the `locale`/`translations`
            // props reflect the resolved locale. On tenant domains IdentifyTenant
            // (route middleware) runs after and overrides with the tenant locale.
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // Payment gateway callbacks carry no session and cannot present a CSRF
        // token — they are authenticated by the provider's own signature, which the
        // gateway adapter verifies before anything is written (SLO-130).
        $middleware->validateCsrfTokens(except: [
            'payments/webhook/*',
        ]);

        $middleware->alias([
            'identify.tenant' => IdentifyTenant::class,
            'ensure.tenant.active' => EnsureTenantActive::class,
            'ensure.user.tenant' => EnsureUserBelongsToTenant::class,
            'ensure.staff' => EnsureUserIsStaff::class,
            'ensure.customer' => EnsureUserIsCustomer::class,
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
            EnsureUserIsStaff::class,
            EnsureUserIsCustomer::class,
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
