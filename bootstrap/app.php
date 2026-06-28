<?php

use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdentifyTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
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
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'identify.tenant' => IdentifyTenant::class,
            'ensure.tenant.active' => EnsureTenantActive::class,
            'ensure.feature' => EnsureFeatureEnabled::class,
            // `can:` is built in; these add spatie's role/permission gates.
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
