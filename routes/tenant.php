<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Super\ImpersonationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Tenant subdomain ({tenant}.{central}). The middleware chain resolves the
// tenant and enforces its status before any tenant route runs (docs/01).
Route::middleware(['identify.tenant', 'ensure.tenant.active'])->group(function () {
    // Public surface (no auth) — the booking front arrives with M4.
    Route::get('/', fn () => Inertia::render('Tenant/Home'))->name('tenant.home');

    // Authenticated tenant area: only members of this tenant (super-admins are
    // redirected to the admin panel unless impersonating). Extendable with
    // ensure.feature + can:.
    Route::middleware(['auth', 'ensure.user.tenant'])->group(function () {
        Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('tenant.dashboard');

        // Sample CRUD assembled from the shared admin building blocks (SLO-15).
        // Real törzsadat pages (SLO-16+) follow this scaffold.
        Route::get('/showcase', fn () => Inertia::render('Admin/Showcase'))->name('tenant.showcase');

        // Locations + rooms master data (SLO-16). Gated by location.manage
        // (tenant-admin only per docs/03). Route-bound models are tenant-scoped
        // (BelongsToTenant global scope → cross-tenant 404).
        Route::middleware('can:'.Permission::LocationManage->value)->group(function () {
            Route::get('/locations', [LocationController::class, 'index'])->name('tenant.locations.index');
            Route::post('/locations', [LocationController::class, 'store'])->name('tenant.locations.store');
            Route::put('/locations/{location}', [LocationController::class, 'update'])->name('tenant.locations.update');
            Route::delete('/locations/{location}', [LocationController::class, 'destroy'])->name('tenant.locations.destroy');

            Route::post('/locations/{location}/rooms', [RoomController::class, 'store'])->name('tenant.rooms.store');
            Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('tenant.rooms.update');
            Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('tenant.rooms.destroy');
        });
    });
});

// Impersonation exit (SLO-79). Same-origin with the tenant pages that show the
// exit banner, so no cross-origin XHR. Deliberately outside ensure.tenant.active
// (a superadmin must be able to leave even a suspended tenant) and outside
// ensure.user.tenant (the actor is a tenant-less superadmin). Only `auth` +
// tenant resolution are needed.
Route::middleware(['identify.tenant', 'auth'])->group(function () {
    Route::delete('/impersonation', [ImpersonationController::class, 'stop'])->name('tenant.impersonation.stop');
});
