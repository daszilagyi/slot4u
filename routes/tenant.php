<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Tenant subdomain ({tenant}.{central}). The middleware chain resolves the
// tenant and enforces its status before any tenant route runs. Extendable
// later with ensure.feature + can: per docs/01.
Route::middleware(['identify.tenant', 'ensure.tenant.active'])->group(function () {
    Route::get('/', fn () => Inertia::render('Tenant/Home'))->name('tenant.home');
});
