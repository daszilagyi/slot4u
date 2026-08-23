<?php

use App\Enums\Feature;
use App\Enums\Permission;
use App\Http\Controllers\Admin\AnalyticsSettingsController;
use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\BookingApprovalController;
use App\Http\Controllers\Admin\BookingController;
use App\Http\Controllers\Admin\CalendarController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\InvoicingSettingsController;
use App\Http\Controllers\Admin\LegalDocumentController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MessageTemplateController;
use App\Http\Controllers\Admin\PrivacyRequestController;
use App\Http\Controllers\Admin\QuoteRequestController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\ScheduleController;
use App\Http\Controllers\Admin\ScheduleExceptionController;
use App\Http\Controllers\Admin\ServiceCategoryController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\UserRbacController;
use App\Http\Controllers\Admin\WaitlistController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\CookieConsentController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\StaffProfileController;
use App\Http\Controllers\Super\ImpersonationController;
use App\Http\Controllers\Tenant\BookingController as TenantBookingController;
use App\Http\Controllers\Tenant\HomeController as TenantHomeController;
use App\Http\Controllers\Tenant\MyBookingController;
use App\Http\Controllers\Tenant\MyInvoiceController;
use App\Http\Controllers\Tenant\MyPaymentController;
use App\Http\Controllers\Tenant\MyPrivacyController;
use App\Http\Controllers\Tenant\MyProfileController;
use App\Http\Controllers\Tenant\MyRequestsController;
use App\Http\Controllers\Tenant\PaymentController;
use App\Http\Controllers\Tenant\SeoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Tenant subdomain ({tenant}.{central}). The middleware chain resolves the
// tenant and enforces its status before any tenant route runs (docs/01).
Route::middleware(['identify.tenant', 'ensure.tenant.active'])->group(function () {
    // Public surface (no auth): the tenant landing page — profile, locations,
    // service catalogue (SLO-29).
    // Throttled like the rest of the public surface (SLO-147): it renders the
    // service catalogue from the database, so it is not a free page to hammer.
    Route::get('/', [TenantHomeController::class, 'index'])
        ->middleware('throttle:public')
        ->name('tenant.home');

    // Per-tenant SEO machine assets (SLO-89). Not Inertia. The branded OG PNG is
    // lazily rendered with GD and cached to the public disk; a light throttle caps
    // the pre-cache GD render cost against a crawler-mimicking burst. Both sit
    // inside ensure.tenant.active, so a suspended tenant gets the 503 status page
    // rather than XML/PNG — intentional (crawlers back off, and robots.txt below,
    // which lives outside that gate, already tells them Disallow: /).
    Route::middleware('throttle:seo')->group(function () {
        Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->name('tenant.sitemap');
        Route::get('/og-image.png', [SeoController::class, 'ogImage'])->name('tenant.og_image');
    });

    // The documents this host asks people to accept (SLO-161). Public, so it
    // carries a named limiter like the rest of the unauthenticated surface
    // (SLO-147) — the `public` bucket, keyed on tenant host + caller, rather
    // than a bespoke one: a legal text is a cheap read, and 60/minute is far
    // beyond anyone actually reading it. A document belonging to another tenant
    // 404s like any cross-tenant id.
    Route::middleware('throttle:public')
        ->get('/legal/{legalDocument}', [LegalController::class, 'show'])
        ->whereNumber('legalDocument')
        ->name('tenant.legal.show');

    // The cookie decision (SLO-165) — see the note on the central copy.
    Route::post('/cookie-consent', [CookieConsentController::class, 'store'])
        ->middleware('throttle:public')
        ->name('tenant.cookie_consent.store');

    // The re-acceptance screen (SLO-161) — see the note on the central copy.
    Route::middleware('auth')->group(function () {
        Route::get('/consent', [ConsentController::class, 'show'])->name('tenant.consent.show');
        Route::post('/consent', [ConsentController::class, 'store'])->name('tenant.consent.store');
    });

    // Public slot-picker (SLO-30) + booking submission (SLO-31). Unauthenticated
    // and availability/write heavy, so both are throttled. The `public` limiter
    // (AppServiceProvider) keys on tenant host + caller, so a burst against one
    // tenant cannot spend another tenant's visitors' allowance (SLO-147).
    // The guest becomes a customer in CreateBooking; a taken slot self-renders
    // back with errors.
    // Route-bound {booking:code} on the confirmation/ICS routes is tenant-scoped
    // (BelongsToTenant → cross-tenant 404).
    Route::middleware('throttle:public')->group(function () {
        Route::get('/book', [TenantBookingController::class, 'index'])->name('tenant.book');
        Route::post('/book', [TenantBookingController::class, 'store'])->name('tenant.book.store');
        // Public no_time_slot order (SLO-101): no slot, no resource — the mode is
        // re-checked in the controller (a non-no_time_slot service 404s).
        Route::post('/order', [TenantBookingController::class, 'storeOrder'])->name('tenant.book.order');
        // Public event sign-up + waitlist join (SLO-100). Route-bound {event} is
        // tenant-scoped (BelongsToTenant → cross-tenant 404).
        Route::post('/events/{event}/book', [TenantBookingController::class, 'storeEvent'])->name('tenant.events.book');
        Route::post('/events/{event}/waitlist', [TenantBookingController::class, 'storeWaitlist'])->name('tenant.events.waitlist');
        Route::get('/waitlisted', [TenantBookingController::class, 'waitlisted'])->name('tenant.waitlisted');
        // Public quote request (SLO-102, docs/04 §6). Gated by the same feature as
        // the admin quote flow; the mode is re-checked in the controller. Creates
        // no booking, so the confirmation is a flashed PRG page, not a code.
        Route::post('/quote', [TenantBookingController::class, 'storeQuote'])
            ->middleware('ensure.feature:'.Feature::QuoteRequest->value)
            ->name('tenant.book.quote');
        Route::get('/quote-sent', [TenantBookingController::class, 'quoteSent'])->name('tenant.quote_sent');
        Route::get('/booked/{booking:code}', [TenantBookingController::class, 'confirmation'])->name('tenant.booked');
        // Guest self-service cancellation (SLO-129). POST on purpose: a GET that
        // cancels would be followed by mail scanners and link-preview bots, and
        // bookings would cancel themselves before anyone read the email.
        Route::post('/booked/{booking:code}/cancel', [TenantBookingController::class, 'cancelPublic'])->name('tenant.booked.cancel');
        Route::get('/booked/{booking:code}/ics', [TenantBookingController::class, 'ics'])->name('tenant.booked.ics');
    });

    // Customer online payment (SLO-130). Public like the booking flow: the booking
    // code / the gateway's transaction reference are the access keys, and both
    // route-bound models are tenant-scoped (cross-tenant → 404). Behind
    // feature_online_payment — a tenant without the integration has nothing to pay
    // with, and its pending_payment bookings simply lapse.
    // Tighter throttle than the booking pages (`checkout`, 20/min): every attempt
    // opens a payment row, so this caps how many one visitor can pile onto a
    // booking. Tenant-keyed like the rest of the public surface (SLO-147).
    Route::middleware(['throttle:checkout', 'ensure.feature:'.Feature::OnlinePayment->value])->group(function () {
        Route::get('/pay/{booking:code}', [PaymentController::class, 'checkout'])->name('tenant.pay');
        // The built-in sandbox gateway's checkout screen (disabled in production).
        Route::get('/payments/sandbox/{payment:provider_ref}', [PaymentController::class, 'sandbox'])->name('tenant.payments.sandbox');
        Route::post('/payments/sandbox/{payment:provider_ref}', [PaymentController::class, 'sandboxOutcome'])->name('tenant.payments.sandbox.outcome');
    });

    // Authenticated tenant admin panel: only *staff* members of this tenant
    // (super-admins are redirected to the admin panel unless impersonating).
    // `ensure.staff` walls the panel off from the customer role — the members
    // area (SLO-33) is a separate group — since several routes here carry no
    // `can:` gate. Extendable with ensure.feature + can:.
    Route::middleware(['auth', 'ensure.user.tenant', 'ensure.staff'])->group(function () {
        // Bento dashboard (SLO-43): today's numbers + agenda + month calendar,
        // each block scoped to what the actor may see (no `can:` gate — a role
        // without booking.view simply gets fewer tiles).
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

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

        // Customer invoice PDF for a booking (SLO-133). Auth + booking.view + the
        // employee visibility scope; the document is on a PRIVATE disk and is only
        // ever streamed through here, never a public URL.
        Route::middleware('can:'.Permission::BookingView->value)->group(function () {
            Route::get('/bookings/{booking}/invoices/{invoice}/pdf', [BookingController::class, 'invoicePdf'])->name('tenant.bookings.invoice_pdf');

            // Day/week time grid of the same bookings (SLO-44), scoped by the same
            // BookingVisibility rule as the list.
            Route::get('/calendar', [CalendarController::class, 'index'])->name('tenant.calendar.index');
        });

        // Statistics module (SLO-137 / SLO-45). Two gates, two questions (docs/01
        // middleware chain): the feature asks whether the TENANT has the module,
        // report.view whether this USER may read it — tenant-admin and manager only
        // per the docs/03 matrix.
        Route::middleware(['ensure.feature:'.Feature::Reports->value, 'can:'.Permission::ReportView->value])->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('tenant.reports.index');
            Route::get('/reports/export', [ReportController::class, 'export'])->name('tenant.reports.export');
        });

        // Booking quick actions (SLO-85). Gated by booking.edit: fulfil a
        // no_time_slot booking (docs/04 §1, SLO-27), mark no-show, reschedule a
        // time-slot booking. Ownership 404 + state-machine 422 in the controller.
        Route::middleware('can:'.Permission::BookingEdit->value)->group(function () {
            Route::post('/bookings/{booking}/complete', [BookingController::class, 'complete'])->name('tenant.bookings.complete');
            Route::post('/bookings/{booking}/no-show', [BookingController::class, 'noShow'])->name('tenant.bookings.no_show');
            Route::post('/bookings/{booking}/reschedule', [BookingController::class, 'reschedule'])->name('tenant.bookings.reschedule');
            // Edit the list price (SLO-126, docs/10 §3.3) — booking upkeep like
            // the other quick actions, but it moves the commission base, so the
            // ledger follows and a settled invoice blocks it (422).
            Route::post('/bookings/{booking}/price', [BookingController::class, 'updatePrice'])->name('tenant.bookings.price');
            // Re-issue an invoice the provider refused (SLO-133) — booking upkeep,
            // like the other quick actions.
            Route::post('/bookings/{booking}/invoices/{invoice}/retry', [BookingController::class, 'retryInvoice'])->name('tenant.bookings.invoice_retry');
        });

        // Cancel a booking (SLO-85). Gated by booking.cancel (a distinct permission
        // from booking.edit per docs/03); admin-initiated, no deadline check.
        Route::middleware('can:'.Permission::BookingCancel->value)->group(function () {
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('tenant.bookings.cancel');
            // Manual refund on top of the tenant's automatic policy (SLO-131) —
            // the money side of calling a booking off, so it shares booking.cancel.
            Route::post('/bookings/{booking}/refund', [BookingController::class, 'refund'])->name('tenant.bookings.refund');
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

        // Invoicing configuration (SLO-167). Behind the same feature flag that
        // gates invoicing itself, plus settings.edit: choosing a provider and
        // handing over its credential is a tenant-admin job.
        Route::middleware(['ensure.feature:'.Feature::Invoicing->value, 'can:'.Permission::SettingsEdit->value])->group(function () {
            Route::get('/settings/invoicing', [InvoicingSettingsController::class, 'index'])->name('tenant.invoicing.index');
            Route::post('/settings/invoicing', [InvoicingSettingsController::class, 'update'])->name('tenant.invoicing.update');
        });

        // The tenant's own measurement ids (SLO-56). Behind the analytics feature
        // AND settings.edit — pointing the public pages at a GA4 property and a
        // Meta pixel is a decision about where the tenant's visitors are
        // reported to, which is the same weight as the rest of the settings.
        Route::middleware(['ensure.feature:'.Feature::Analytics->value, 'can:'.Permission::SettingsEdit->value])->group(function () {
            Route::get('/settings/analytics', [AnalyticsSettingsController::class, 'index'])->name('tenant.analytics.index');
            Route::post('/settings/analytics', [AnalyticsSettingsController::class, 'update'])->name('tenant.analytics.update');
        });

        // Custom domains (SLO-42). Behind the feature flag AND settings.edit:
        // the capability is off for most tenants (403 from ensure.feature), and
        // configuring it is a tenant-admin job. {tenantDomain} binds through the
        // BelongsToTenant scope, so another tenant's id 404s.
        Route::middleware(['ensure.feature:'.Feature::CustomDomain->value, 'can:'.Permission::SettingsEdit->value])->group(function () {
            Route::get('/settings/domains', [DomainController::class, 'index'])->name('tenant.domains.index');
            Route::post('/settings/domains', [DomainController::class, 'store'])->name('tenant.domains.store');
            Route::post('/settings/domains/{tenantDomain}/verify', [DomainController::class, 'verify'])->name('tenant.domains.verify');
            Route::post('/settings/domains/{tenantDomain}/primary', [DomainController::class, 'primary'])->name('tenant.domains.primary');
            Route::post('/settings/domains/{tenantDomain}/provision', [DomainController::class, 'provision'])->name('tenant.domains.provision');
            Route::delete('/settings/domains/{tenantDomain}', [DomainController::class, 'destroy'])->name('tenant.domains.destroy');
        });

        // Tenant role editor (SLO-141, SLO-142). Gated by role.manage
        // (tenant-admin only per docs/03). {role} is a role NAME, not an id:
        // roles are per-tenant spatie rows, so the controller resolves it inside
        // the tenant team — an unknown name 404s, a locked one (tenant-admin,
        // customer, the actor's own, a built-in on rename/delete) 403s from the
        // RolePolicy.
        //
        // The per-user overrides live under /settings/users rather than under
        // /settings/roles/... on purpose: {role} matches any string, so a
        // sibling path segment there would be ambiguous with a role literally
        // named "users".
        Route::middleware('can:'.Permission::RoleManage->value)->group(function () {
            Route::get('/settings/roles', [RoleController::class, 'index'])->name('tenant.roles.index');
            Route::post('/settings/roles', [RoleController::class, 'store'])->name('tenant.roles.store');
            Route::put('/settings/roles/{role}', [RoleController::class, 'update'])->name('tenant.roles.update');
            Route::patch('/settings/roles/{role}/name', [RoleController::class, 'rename'])->name('tenant.roles.rename');
            Route::delete('/settings/roles/{role}', [RoleController::class, 'destroy'])->name('tenant.roles.destroy');
            Route::post('/settings/roles/{role}/reset', [RoleController::class, 'reset'])->name('tenant.roles.reset');

            Route::put('/settings/users/{user}/rbac', [UserRbacController::class, 'update'])->name('tenant.users.rbac.update');
        });

        // Data-subject request queue (SLO-159, docs/19). Gated by privacy.manage,
        // which is seeded to tenant-admin only but — unlike role.manage — is
        // grantable, so a tenant can put its data-protection contact on it
        // without also handing them billing.
        Route::middleware('can:'.Permission::PrivacyManage->value)->group(function () {
            // The tenant's own terms and privacy notice (SLO-161). Same gate as
            // the request queue: whoever answers erasure requests is whoever owns
            // what the notice says.
            Route::get('/settings/legal', [LegalDocumentController::class, 'index'])->name('tenant.legal.index');
            Route::post('/settings/legal', [LegalDocumentController::class, 'store'])->name('tenant.legal.store');
            Route::delete('/settings/legal/{legalDocument}', [LegalDocumentController::class, 'destroy'])
                ->whereNumber('legalDocument')
                ->name('tenant.legal.destroy');

            Route::get('/settings/privacy', [PrivacyRequestController::class, 'index'])->name('tenant.privacy.index');
            // The tenant's own full data set (SLO-160, docs/19 §7.4). Declared
            // before the {privacyRequest} routes below only for readability —
            // those are POSTs, so "export" could never be bound as an id.
            Route::get('/settings/privacy/export', [PrivacyRequestController::class, 'export'])->name('tenant.privacy.export');
            Route::post('/settings/privacy/{privacyRequest}/approve', [PrivacyRequestController::class, 'approve'])->name('tenant.privacy.approve');
            Route::post('/settings/privacy/{privacyRequest}/reject', [PrivacyRequestController::class, 'reject'])->name('tenant.privacy.reject');
        });

        // Tenant-editable email templates (SLO-112/SLO-114). Gated by
        // template.manage (tenant-admin only per docs/03). The override renders in
        // place of the built-in default for the matching notification (SLO-113);
        // {key} is a NotificationType value, validated against the editable set.
        Route::middleware('can:'.Permission::TemplateManage->value)->group(function () {
            Route::get('/settings/templates', [MessageTemplateController::class, 'index'])->name('tenant.templates.index');
            Route::put('/settings/templates/{key}', [MessageTemplateController::class, 'update'])->name('tenant.templates.update');
            Route::delete('/settings/templates/{key}', [MessageTemplateController::class, 'destroy'])->name('tenant.templates.destroy');
        });

        // Employee self-service profile (SLO-17). Available to every *staff*
        // member (not staff.manage-gated, but behind ensure.staff like the rest
        // of the panel — a customer cannot reach it): a user linked to a staff
        // record edits their own profile; ownership is enforced by the
        // StaffPolicy update ability.
        Route::get('/profile', [StaffProfileController::class, 'edit'])->name('tenant.profile.edit');
        Route::put('/profile', [StaffProfileController::class, 'update'])->name('tenant.profile.update');
    });

    // Members area (SLO-33): a logged-in customer's own account. A separate group
    // from the admin panel — `ensure.customer` (not `ensure.staff`) gates it, so a
    // staff user is kept out and a customer is kept out of the admin panel. Every
    // route is customer-self-scoped: the list filters by customer_id, per-record
    // routes 404 a booking that isn't theirs. Route-bound {booking} is tenant-scoped
    // (BelongsToTenant global scope → cross-tenant 404).
    Route::middleware(['auth', 'ensure.user.tenant', 'ensure.customer'])->group(function () {
        Route::get('/my/bookings', [MyBookingController::class, 'index'])->name('tenant.my.bookings');
        Route::post('/my/bookings/{booking}/cancel', [MyBookingController::class, 'cancel'])->name('tenant.my.bookings.cancel');
        // Online reschedule (SLO-97): re-pick a slot for a time-slot booking. The
        // POST runs through RescheduleBooking with online: true, so the tenant
        // cancellation deadline is enforced (a within-deadline move is refused).
        Route::get('/my/bookings/{booking}/reschedule', [MyBookingController::class, 'rescheduleForm'])->name('tenant.my.bookings.reschedule.form');
        Route::post('/my/bookings/{booking}/reschedule', [MyBookingController::class, 'reschedule'])->name('tenant.my.bookings.reschedule');
        Route::get('/my/bookings/{booking}/ics', [MyBookingController::class, 'ics'])->name('tenant.my.bookings.ics');

        // Profile self-service (SLO-96): the customer edits their own name/phone
        // and password. Every action targets $request->user() — no id, so it is
        // inherently self-scoped.
        Route::get('/my/profile', [MyProfileController::class, 'edit'])->name('tenant.my.profile');
        Route::put('/my/profile', [MyProfileController::class, 'update'])->name('tenant.my.profile.update');
        // Password change is throttled: the current_password check makes it a
        // (session-bound) guessing surface.
        Route::put('/my/password', [MyProfileController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('tenant.my.password.update');

        // Data protection self-service (SLO-159, docs/19): the art. 15 copy and
        // the art. 17 request. Not feature-gated — these are statutory rights,
        // not a product tier, so no tenant configuration may switch them off.
        Route::get('/my/privacy', [MyPrivacyController::class, 'index'])->name('tenant.my.privacy');
        // The download assembles a complete personal-data set on every hit;
        // throttled so it cannot be used to hammer the database from a session.
        Route::get('/my/privacy/export', [MyPrivacyController::class, 'download'])
            ->middleware('throttle:6,1')
            ->name('tenant.my.privacy.export');
        Route::post('/my/privacy/erasure', [MyPrivacyController::class, 'requestErasure'])
            ->middleware('throttle:6,1')
            ->name('tenant.my.privacy.erasure');

        // My waitlist positions (SLO-98). Feature-gated; the members nav only shows
        // the link when the feature is on (shared `features` prop), so a customer
        // never hits the 403.
        Route::get('/my/waitlist', [MyRequestsController::class, 'waitlist'])
            ->middleware('ensure.feature:'.Feature::Waitlist->value)
            ->name('tenant.my.waitlist');
        // My quote requests (SLO-98). Feature-gated the same way.
        Route::get('/my/quotes', [MyRequestsController::class, 'quotes'])
            ->middleware('ensure.feature:'.Feature::QuoteRequest->value)
            ->name('tenant.my.quotes');
        // My online payments and their refunds (SLO-132). Self-scoped through the
        // booking's customer_id; feature-gated like the rest of the payment surface.
        Route::get('/my/payments', [MyPaymentController::class, 'index'])
            ->middleware('ensure.feature:'.Feature::OnlinePayment->value)
            ->name('tenant.my.payments');
        // My invoices (SLO-133). Self-scoped through the booking; the PDF is
        // streamed from the private disk behind this same scope.
        Route::middleware('ensure.feature:'.Feature::Invoicing->value)->group(function () {
            Route::get('/my/invoices', [MyInvoiceController::class, 'index'])->name('tenant.my.invoices');
            Route::get('/my/invoices/{invoice}/pdf', [MyInvoiceController::class, 'download'])->name('tenant.my.invoices.pdf');
        });
    });
});

// Commission transparency dashboard (SLO-70, docs/10 §9). Deliberately OUTSIDE
// ensure.tenant.active (SLO-120): a tenant is suspended *for non-payment*
// (docs/10 §6.6), so its admin must still reach the invoices to see what to pay
// and clear the suspension — docs/03 spells this out ("csak figyelmeztető +
// jutalékszámla-fizetés oldal"). The public booking surface and the rest of the
// admin panel stay 503. Still fully gated: tenant resolved (archived tenants 404
// at identify.tenant — soft-deleted, never resolve) + auth + staff (customers
// out) + billing.view (tenant-admin only, docs/03). Read-only; route-bound
// {invoice} is tenant-scoped → cross-tenant 404.
Route::middleware(['identify.tenant', 'auth', 'ensure.user.tenant', 'ensure.staff', 'can:'.Permission::BillingView->value])->group(function () {
    Route::get('/billing', [BillingController::class, 'index'])->name('tenant.billing.index');
    Route::get('/billing/export', [BillingController::class, 'export'])->name('tenant.billing.export');
    Route::get('/billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('tenant.billing.invoice_pdf');
});

// Payment gateway callbacks (SLO-130). Deliberately OUTSIDE ensure.tenant.active
// and outside the feature gate: a callback for a payment made just before the
// tenant was suspended (or before the integration was switched off) must still be
// recorded — money that arrived is a fact, not a permission (the SLO-120 pattern).
// Authenticated by the gateway's own signature, not by session/CSRF (exempted in
// bootstrap/app.php); the payment is looked up tenant-scoped, so a reference from
// another tenant's gateway account 404s.
// Generously throttled (`webhook`), not tightly: a refused delivery can mean a
// payment the app never learns about, and gateways differ on whether they retry a
// 429. The limit is an amplification guard, not an access control — the signature
// check is what authenticates (SLO-147).
Route::middleware(['identify.tenant', 'throttle:webhook'])->group(function () {
    Route::post('/payments/webhook/{provider}', [PaymentController::class, 'webhook'])->name('tenant.payments.webhook');
});

// robots.txt must answer for a suspended tenant too (Disallow: /), so it sits
// outside ensure.tenant.active — only the tenant needs resolving (SLO-89).
Route::middleware(['identify.tenant', 'throttle:seo'])->group(function () {
    Route::get('/robots.txt', [SeoController::class, 'robots'])->name('tenant.robots');
});

// Impersonation exit (SLO-79). Same-origin with the tenant pages that show the
// exit banner, so no cross-origin XHR. Deliberately outside ensure.tenant.active
// (a superadmin must be able to leave even a suspended tenant) and outside
// ensure.user.tenant (the actor is a tenant-less superadmin). Only `auth` +
// tenant resolution are needed.
Route::middleware(['identify.tenant', 'auth'])->group(function () {
    Route::delete('/impersonation', [ImpersonationController::class, 'stop'])->name('tenant.impersonation.stop');
});
