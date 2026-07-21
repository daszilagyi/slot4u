<?php

use App\Http\Controllers\Super\AuditLogController;
use App\Http\Controllers\Super\CommissionController;
use App\Http\Controllers\Super\CommissionInvoiceController;
use App\Http\Controllers\Super\ImpersonationController;
use App\Http\Controllers\Super\TenantController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Superadmin panel (admin.{central}) — no tenant context. Gated to platform
// super-admins (tenant_id = null).
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

    // Impersonation start (SLO-79). No withTrashed: an archived tenant 404s on
    // binding here (and would 404 on its subdomain anyway), so it can't be
    // impersonated. The matching stop route lives on the tenant domain
    // (routes/tenant.php) so the exit button is same-origin.
    Route::post('/tenants/{tenant}/impersonate', [ImpersonationController::class, 'store'])->name('super.tenants.impersonate');

    // Audit log viewer (SLO-78).
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('super.audit-logs.index');

    // Commission configuration (SLO-121, docs/10 §10). Versions are immutable:
    // publishing is the only write, hence no update/destroy route. The tenant
    // override hangs off the tenant it prices; withTrashed to match the other
    // tenant routes (an archived tenant still has billing history).
    Route::get('/commission', [CommissionController::class, 'index'])->name('super.commission.index');
    Route::post('/commission', [CommissionController::class, 'store'])->name('super.commission.store');
    Route::put('/tenants/{tenant}/commission-override', [CommissionController::class, 'updateOverride'])->withTrashed()->name('super.tenants.commission-override.update');
    Route::delete('/tenants/{tenant}/commission-override', [CommissionController::class, 'clearOverride'])->withTrashed()->name('super.tenants.commission-override.destroy');

    // Commission invoice management (SLO-122, docs/10 §10). The cross-tenant
    // invoice list plus manual settle / void / resend. Settle and void are gated
    // to outstanding invoices in the controller; resend is throttled as a mail
    // spam guard on top of the superadmin gate.
    Route::get('/commission-invoices', [CommissionInvoiceController::class, 'index'])->name('super.commission-invoices.index');
    Route::post('/commission-invoices/{invoice}/mark-paid', [CommissionInvoiceController::class, 'markPaid'])->name('super.commission-invoices.mark-paid');
    Route::post('/commission-invoices/{invoice}/void', [CommissionInvoiceController::class, 'void'])->name('super.commission-invoices.void');
    Route::post('/commission-invoices/{invoice}/resend', [CommissionInvoiceController::class, 'resend'])->middleware('throttle:12,1')->name('super.commission-invoices.resend');
});
