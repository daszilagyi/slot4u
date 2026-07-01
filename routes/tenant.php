<?php

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
