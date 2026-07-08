<?php

use App\Enums\Feature;
use App\Enums\Permission;
use App\Http\Controllers\Admin\BookingApprovalController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\QuoteRequestController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ScheduleExceptionController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\WaitlistController;
use App\Http\Controllers\StaffProfileController;
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

        // Staff master data + employee invitations (SLO-17). Gated by
        // staff.manage (tenant-admin per docs/03). Route-bound Staff is
        // tenant-scoped (BelongsToTenant global scope → cross-tenant 404).
        Route::middleware('can:'.Permission::StaffManage->value)->group(function () {
            Route::get('/staff', [StaffController::class, 'index'])->name('tenant.staff.index');
            Route::post('/staff', [StaffController::class, 'store'])->name('tenant.staff.store');
            Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('tenant.staff.update');
            Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('tenant.staff.destroy');
        });

        // Services + categories master data (SLO-18). Gated by service.manage
        // (tenant-admin per docs/03). Route-bound models are tenant-scoped
        // (BelongsToTenant global scope → cross-tenant 404).
        Route::middleware('can:'.Permission::ServiceManage->value)->group(function () {
            Route::get('/services', [ServiceController::class, 'index'])->name('tenant.services.index');
            Route::post('/services', [ServiceController::class, 'store'])->name('tenant.services.store');
            Route::put('/services/{service}', [ServiceController::class, 'update'])->name('tenant.services.update');
            Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('tenant.services.destroy');

            Route::post('/service-categories', [ServiceCategoryController::class, 'store'])->name('tenant.service_categories.store');
            Route::put('/service-categories/{category}', [ServiceCategoryController::class, 'update'])->name('tenant.service_categories.update');
            Route::delete('/service-categories/{category}', [ServiceCategoryController::class, 'destroy'])->name('tenant.service_categories.destroy');
        });

        // Weekly schedules + exceptions master data (SLO-19). Gated by
        // schedule.manage (tenant-admin + manager per docs/03). Route-bound models
        // are tenant-scoped (BelongsToTenant global scope → cross-tenant 404).
        Route::middleware('can:'.Permission::ScheduleManage->value)->group(function () {
            Route::get('/schedule', [ScheduleController::class, 'index'])->name('tenant.schedule.index');
            Route::post('/schedule/entries', [ScheduleController::class, 'store'])->name('tenant.schedule.store');
            Route::put('/schedule/entries/{schedule}', [ScheduleController::class, 'update'])->name('tenant.schedule.update');
            Route::delete('/schedule/entries/{schedule}', [ScheduleController::class, 'destroy'])->name('tenant.schedule.destroy');
            Route::post('/schedule/copy-day', [ScheduleController::class, 'copyDay'])->name('tenant.schedule.copy_day');

            Route::post('/schedule/exceptions', [ScheduleExceptionController::class, 'store'])->name('tenant.schedule_exceptions.store');
            Route::delete('/schedule/exceptions/{exception}', [ScheduleExceptionController::class, 'destroy'])->name('tenant.schedule_exceptions.destroy');

            // Announced events for event_based services (SLO-20). Same gate as
            // schedules (meghirdetett alkalmak = operatív scheduling).
            Route::get('/events', [EventController::class, 'index'])->name('tenant.events.index');
            Route::post('/events', [EventController::class, 'store'])->name('tenant.events.store');
            Route::put('/events/{event}', [EventController::class, 'update'])->name('tenant.events.update');
            Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('tenant.events.destroy');
            Route::post('/events/{event}/cancel', [EventController::class, 'cancel'])->name('tenant.events.cancel');
        });

        // Admin booking list (SLO-85). Gated by booking.view. The list is tenant-
        // scoped and further narrowed to the actor's own bookings for an employee
        // ({@see BookingVisibility}); a hidden booking 404s on show, like a
        // cross-tenant id. Route-bound {booking} is tenant-scoped (BelongsToTenant).
        Route::middleware('can:'.Permission::BookingView->value)->group(function () {
            Route::get('/bookings', [BookingController::class, 'index'])->name('tenant.bookings.index');
            Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('tenant.bookings.show');
        });

        // Admin-created bookings (SLO-24). Gated by booking.create (manager/employee
        // per docs/03). CreateBooking is race-safe.
        Route::middleware('can:'.Permission::BookingCreate->value)->group(function () {
            Route::post('/bookings', [BookingController::class, 'store'])->name('tenant.bookings.store');
            // Join a full event's FIFO waitlist (SLO-25). Feature-gated in WaitlistRequest.
            Route::post('/bookings/waitlist', [WaitlistController::class, 'store'])->name('tenant.bookings.waitlist');
        });

        // Booking quick actions (SLO-85). Gated by booking.edit: fulfil a
        // no_time_slot booking (docs/04 §1, SLO-27), mark no-show, reschedule a
        // time-slot booking. Ownership 404 + state-machine 422 in the controller.
        Route::middleware('can:'.Permission::BookingEdit->value)->group(function () {
            Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete'])->name('tenant.bookings.complete');
            Route::post('/bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('tenant.bookings.no_show');
            Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule'])->name('tenant.bookings.reschedule');
        });

        // Cancel a booking (SLO-85). Gated by booking.cancel (a distinct permission
        // from booking.edit per docs/03); admin-initiated, no deadline check.
        Route::middleware('can:'.Permission::BookingCancel->value)->group(function () {
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('tenant.bookings.cancel');
        });

        // Quote request flow (quote_request mode, docs/04 §6, SLO-27). Gated by
        // feature_quote_request; creating needs booking.create, every lifecycle
        // action booking.edit. Route-bound {quoteRequest} is tenant-scoped. The
        // full admin list/UI is SLO-28 — these are the write paths it drives.
        Route::middleware('ensure.feature:'.Feature::QuoteRequest->value)->group(function () {
            Route::middleware('can:'.Permission::BookingCreate->value)->group(function () {
                Route::post('/quote-requests', [QuoteRequestController::class, 'store'])->name('tenant.quote_requests.store');
            });

            Route::middleware('can:'.Permission::BookingEdit->value)->group(function () {
                Route::post('/quote-requests/{quoteRequest}/start', [QuoteRequestController::class, 'start'])->name('tenant.quote_requests.start');
                Route::post('/quote-requests/{quoteRequest}/submit', [QuoteRequestController::class, 'submit'])->name('tenant.quote_requests.submit');
                Route::post('/quote-requests/{quoteRequest}/accept', [QuoteRequestController::class, 'accept'])->name('tenant.quote_requests.accept');
                Route::post('/quote-requests/{quoteRequest}/reject', [QuoteRequestController::class, 'reject'])->name('tenant.quote_requests.reject');
                Route::post('/quote-requests/{quoteRequest}/messages', [QuoteRequestController::class, 'message'])->name('tenant.quote_requests.message');
            });
        });

        // Approval decisions on requested bookings (manual_approval, SLO-26). Gated
        // by feature_approval_flow + booking.approve (docs/03/04 §5).
        Route::middleware(['ensure.feature:'.Feature::ApprovalFlow->value, 'can:'.Permission::BookingApprove->value])->group(function () {
            Route::post('/bookings/{booking}/approve', [BookingApprovalController::class, 'approve'])->name('tenant.bookings.approve');
            Route::post('/bookings/{booking}/reject', [BookingApprovalController::class, 'reject'])->name('tenant.bookings.reject');
            Route::post('/bookings/{booking}/propose', [BookingApprovalController::class, 'propose'])->name('tenant.bookings.propose');
        });

        // Customer management (SLO-84): the tenant's customers = users with the
        // customer role. Viewing needs customer.view, writing customer.edit
        // (docs/03). Route-bound {customer} is tenant + role scoped
        // (Customer::resolveRouteBinding → cross-tenant/non-customer 404); an
        // employee is further restricted to their own customers in the controller.
        Route::middleware('can:'.Permission::CustomerView->value)->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('tenant.customers.index');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('tenant.customers.show');
        });
        Route::middleware('can:'.Permission::CustomerEdit->value)->group(function () {
            Route::post('/customers', [CustomerController::class, 'store'])->name('tenant.customers.store');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('tenant.customers.update');
        });

        // Company profile + branding settings (SLO-21). Gated by settings.edit
        // (tenant-admin per docs/03). Update is POST (multipart logo/cover upload);
        // the branding section is feature-gated in SettingsRequest.
        Route::middleware('can:'.Permission::SettingsEdit->value)->group(function () {
            Route::get('/settings', [SettingsController::class, 'edit'])->name('tenant.settings.edit');
            Route::post('/settings', [SettingsController::class, 'update'])->name('tenant.settings.update');
        });

        // Employee self-service profile (SLO-17). Available to every tenant user
        // (not staff.manage): a user linked to a staff record edits their own
        // profile; ownership is enforced by the StaffPolicy update ability.
        Route::get('/profile', [StaffProfileController::class, 'edit'])->name('tenant.profile.edit');
        Route::put('/profile', [StaffProfileController::class, 'update'])->name('tenant.profile.update');
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
