<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Superadmin panel (admin.{central}) — no tenant context. Gated to platform
// super-admins (tenant_id = null); the full panel + impersonation arrive with
// SLO-14.
Route::middleware(['auth', 'ensure.superadmin'])->group(function () {
    Route::get('/', fn () => Inertia::render('Super/Dashboard'))->name('super.dashboard');
});
