<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Tenant subdomain ({tenant}.{central}). The middleware chain resolves the
// tenant and enforces its status before any tenant route runs (docs/01).
Route::middleware(['identify.tenant', 'ensure.tenant.active'])->group(function () {
    // Public surface (no auth) — the booking front arrives with M4.
    Route::get('/', fn () => Inertia::render('Tenant/Home'))->name('tenant.home');

    // Authenticated tenant area: only members of this tenant (super-admins are
    // redirected to the admin panel). Extendable with ensure.feature + can:.
    Route::middleware(['auth', 'ensure.user.tenant'])->group(function () {
        Route::get('/dashboard', fn () => Inertia::render('Tenant/Dashboard'))->name('tenant.dashboard');
    });
});
