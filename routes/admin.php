<?php

use App\Http\Controllers\Super\TenantController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Superadmin panel (admin.{central}) — no tenant context. Gated to platform
// super-admins (tenant_id = null); audit log + impersonation arrive with
// SLO-78 / SLO-79.
Route::middleware(['auth', 'ensure.superadmin'])->group(function () {
    Route::get('/', fn () => Inertia::render('Super/Dashboard'))->name('super.dashboard');

    // Tenant management (SLO-77). withTrashed so archived (soft-deleted) tenants
    // still resolve for the superadmin on the detail/action routes.
    Route::get('/tenants', [TenantController::class, 'index'])->name('super.tenants.index');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show'])->withTrashed()->name('super.tenants.show');
    Route::put('/tenants/{tenant}', [TenantController::class, 'update'])->withTrashed()->name('super.tenants.update');
    Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend'])->withTrashed()->name('super.tenants.suspend');
    Route::post('/tenants/{tenant}/activate', [TenantController::class, 'activate'])->withTrashed()->name('super.tenants.activate');
    Route::post('/tenants/{tenant}/archive', [TenantController::class, 'archive'])->withTrashed()->name('super.tenants.archive');
    Route::post('/tenants/{tenant}/extend-trial', [TenantController::class, 'extendTrial'])->withTrashed()->name('super.tenants.extend-trial');
    Route::post('/tenants/{tenant}/features', [TenantController::class, 'toggleFeature'])->withTrashed()->name('super.tenants.features');
});
