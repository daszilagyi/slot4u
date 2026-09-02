<?php

use App\Actions\Payment\StartBookingPayment;
use App\Enums\BillingPeriodStatus;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\InvoiceProvider;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentProvider;
use App\Enums\TenantStatus;
use App\Models\Booking;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBillingPeriod;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Notifications\CommissionInvoiceNotification;
use App\Notifications\GuestRecipient;
use App\Notifications\StaffInvitationNotification;
use App\Notifications\TenantArchivedNotification;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Notification\Notifier;
use App\Services\Payment\PaymentGatewayManager;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Demo tenant guardrails (SLO-182, docs/20 §3.1)
|--------------------------------------------------------------------------
|
| The demo tenants are seeded with lifelike data and are meant to be played
| with by strangers on the public internet. Four things must therefore be
| impossible from one, and each has a cost that is paid outside the app:
|
|   - a real email leaving (a bounce, or a stranger receiving a confirmation
|     for an appointment that does not exist),
|   - a real payment starting (a live card form on slot4u's merchant account),
|   - a real invoice being issued (a document filed with the tax authority in
|     a fabricated customer's name),
|   - the fabricated turnover landing in the numbers slot4u steers by.
|
| Every test below pairs the demo case with the non-demo one. A guardrail that
| is really "the feature is switched off for everybody" passes half of these
| and fails the other half, which is the point of writing them in pairs.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-07-05 09:00:00');
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    config()->set('mail.from.address', 'no-reply@slot4u.hu');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A booking that a confirmation mail can be rendered for. */
function demoBookingFor(Tenant $tenant): Booking
{
    $service = Service::factory()->forTenant($tenant)->create([
        'booking_mode' => BookingMode::DurationBased,
        'duration_minutes' => 60,
    ]);

    return Booking::factory()->forTenant($tenant)->create([
        'service_id' => $service->id,
        'customer_id' => null,
        'guest_name' => 'Teszt Vendég',
        'guest_email' => 'vendeg@example.test',
        'status' => BookingStatus::Confirmed,
        'starts_at' => Carbon::now()->addDays(2),
        'ends_at' => Carbon::now()->addDays(2)->addHour(),
    ]);
}

/** The mailer a rendered customer confirmation would be sent through. */
function confirmationMailer(Tenant $tenant): ?string
{
    app(TenantManager::class)->set($tenant);

    $notification = new BookingConfirmedNotification(demoBookingFor($tenant), $tenant);

    return $notification->toMail(new GuestRecipient('vendeg@example.test', 'Teszt Vendég'))->mailer;
}

// --- Mail: diverted, not cancelled -----------------------------------------

it('routes a demo tenant customer mail to the log mailer', function () {
    expect(confirmationMailer(Tenant::factory()->demo()->create()))->toBe('log');
});

it('leaves a real tenant customer mail on the configured mailer', function () {
    // null = "no override", i.e. whatever the platform is configured with. The
    // pair matters: a guardrail that diverted everyone's mail would pass the
    // test above on its own.
    expect(confirmationMailer(Tenant::factory()->active()->create()))->toBeNull();
});

it('routes the platform mails to a demo tenant to the log mailer too', function () {
    $demo = Tenant::factory()->demo()->create();
    $real = Tenant::factory()->active()->create();
    $recipient = User::factory()->create(['tenant_id' => $demo->id]);

    $invoiceFor = fn (Tenant $tenant) => CommissionInvoice::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => '2026-06',
        'status' => CommissionInvoiceStatus::Issued,
        'due_at' => Carbon::parse('2026-06-28'),
    ]);

    // The three platform→tenant mails do not share the customer-mail base class,
    // so each one carries the guard itself and each one is asserted.
    expect((new CommissionInvoiceNotification($invoiceFor($demo), $demo))->toMail($recipient)->mailer)->toBe('log')
        ->and((new StaffInvitationNotification($demo, 'token-123'))->toMail($recipient)->mailer)->toBe('log')
        ->and((new TenantArchivedNotification($demo, Carbon::now()->addDays(90)))->toMail($recipient)->mailer)->toBe('log')
        // ...and the same three stay on the wire for a real tenant.
        ->and((new CommissionInvoiceNotification($invoiceFor($real), $real))->toMail($recipient)->mailer)->toBeNull()
        ->and((new StaffInvitationNotification($real, 'token-123'))->toMail($recipient)->mailer)->toBeNull()
        ->and((new TenantArchivedNotification($real, Carbon::now()->addDays(90)))->toMail($recipient)->mailer)->toBeNull();
});

it('still records a demo tenant notification in notifications_log', function () {
    $demo = Tenant::factory()->demo()->create();
    app(TenantManager::class)->set($demo);
    $booking = demoBookingFor($demo);

    // Lift the suite-wide Notification::fake() so the message reaches a transport.
    Notification::swap(new ChannelManager(app()));

    $log = app(Notifier::class)->sendOnce(
        $demo,
        NotificationType::BookingConfirmed,
        'demo-guardrail-'.$booking->id,
        new GuestRecipient('vendeg@example.test', 'Teszt Vendég'),
        'vendeg@example.test',
        new BookingConfirmedNotification($booking, $demo),
    );

    // Nothing on the array transport: the mail went to the log one instead.
    expect(Mail::getSymfonyTransport()->messages())->toHaveCount(0)
        ->and($log)->not->toBeNull();

    // ...but the ledger row is finalised exactly as a real delivery would be.
    // This is the half that makes the demo's notification screen truthful, and
    // the half a `return` in via() would have thrown away.
    $row = NotificationLog::withoutGlobalScopes()->findOrFail($log->getKey());

    expect($row->status)->toBe(NotificationStatus::Sent)
        ->and($row->recipient)->toBe('vendeg@example.test');
});

// --- Payment ---------------------------------------------------------------

it('pins a demo tenant to the sandbox gateway even when a real one is configured', function () {
    // Barion has no adapter yet, so `default()` throwing is exactly how we can
    // tell the demo branch was taken rather than merely agreeing with the
    // default. Once a real adapter ships this test keeps its meaning.
    config()->set('payments.default', PaymentProvider::Barion->value);

    $gateways = app(PaymentGatewayManager::class);

    expect($gateways->forTenant(Tenant::factory()->demo()->create())->provider())
        ->toBe(PaymentProvider::Sandbox);

    expect(fn () => $gateways->forTenant(Tenant::factory()->active()->create()))
        ->toThrow(RuntimeException::class);
});

it('keeps a demo tenant on the sandbox gateway after it is archived', function () {
    // Archiving soft-deletes the tenant. A demo tenant does not stop being one
    // then, and the booking's `tenant` relation would resolve to null — which is
    // why StartBookingPayment reads it withTrashed.
    config()->set('payments.default', PaymentProvider::Barion->value);

    $demo = Tenant::factory()->demo()->create();
    app(TenantManager::class)->set($demo);
    $booking = demoBookingFor($demo);
    $booking->status = BookingStatus::PendingPayment;
    $booking->saveQuietly();
    $demo->delete();

    $session = app(StartBookingPayment::class)($booking, 'https://demo.test/back');

    expect($session)->not->toBeNull()
        ->and(Payment::withoutGlobalScopes()->where('booking_id', $booking->id)->sole()->provider)
        ->toBe(PaymentProvider::Sandbox);
});

// --- Invoicing -------------------------------------------------------------

it('pins a demo tenant to the sandbox issuer even when it chose Billingo', function () {
    $demo = Tenant::factory()->demo()->create();
    $demo->invoicing = ['provider' => InvoiceProvider::Billingo->value, 'api_key' => 'live-key'];
    $demo->save();

    $real = Tenant::factory()->active()->create();
    $real->invoicing = ['provider' => InvoiceProvider::Billingo->value, 'api_key' => 'live-key'];
    $real->save();

    $issuers = app(InvoiceIssuerManager::class);

    // The tenant's own choice is not even read for a demo tenant — a real
    // Billingo call would number a document into a live block and file it.
    expect($issuers->forTenant($demo)->provider())->toBe(InvoiceProvider::Sandbox)
        ->and($issuers->forTenant($real)->provider())->toBe(InvoiceProvider::Billingo);
});

// --- Billing: never invoiced, never suspended ------------------------------

it('never closes a demo tenant billing period', function () {
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);

    $demo = Tenant::factory()->demo()->create();
    $real = Tenant::factory()->active()->create();

    // June: the grace (2nd of July) has passed at now = 2026-07-05, so a real
    // tenant's period closes on this run.
    foreach ([$demo, $real] as $tenant) {
        TenantBillingPeriod::factory()->create([
            'tenant_id' => $tenant->id,
            'period' => '2026-06',
            'status' => BillingPeriodStatus::Open,
            'turnover_minor' => 5_000_000,
        ]);
    }

    $this->artisan('billing:close-periods')->assertSuccessful();

    $statusOf = fn (Tenant $tenant) => TenantBillingPeriod::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)->sole()->status;

    expect($statusOf($demo))->toBe(BillingPeriodStatus::Open)
        ->and($statusOf($real))->not->toBe(BillingPeriodStatus::Open)
        // The demo tenant is billed for nothing at all, not merely billed late.
        ->and(CommissionInvoice::withoutGlobalScopes()->where('tenant_id', $demo->id)->count())->toBe(0);
});

it('never suspends a demo tenant for an unpaid commission invoice', function () {
    Notification::fake();

    $demo = Tenant::factory()->demo()->create();
    $real = Tenant::factory()->active()->create();

    // Long past the 14-day grace window at now = 2026-07-05.
    foreach ([$demo, $real] as $tenant) {
        CommissionInvoice::factory()->create([
            'tenant_id' => $tenant->id,
            'period' => '2026-05',
            'status' => CommissionInvoiceStatus::Overdue,
            'issued_at' => Carbon::parse('2026-06-01'),
            'due_at' => Carbon::parse('2026-06-09'),
        ]);
    }

    $this->artisan('billing:dunning-sweep')->assertSuccessful();

    // Suspension blocks the public surface — which for a demo tenant is the
    // whole product. The sales demo must not be able to switch itself off.
    expect($demo->fresh()->status)->toBe(TenantStatus::Active)
        ->and($real->fresh()->status)->toBe(TenantStatus::Suspended);
});

// --- Statistics ------------------------------------------------------------

it('leaves demo tenants out of the platform statistics', function () {
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 1_000_000,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);

    $real = Tenant::factory()->active()->create();
    $demo = Tenant::factory()->demo()->create();

    // One booking each, both inside the running month.
    foreach ([$real, $demo] as $tenant) {
        app(TenantManager::class)->set($tenant);
        $service = Service::factory()->forTenant($tenant)->create();
        Booking::factory()->forTenant($tenant)->create(['service_id' => $service->id]);
    }
    app(TenantManager::class)->forget();

    $this->actingAs(superAdmin())
        ->get(superUrl('/statistics'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Statistics')
            // The demo tenant is neither a tenant nor a signup nor a booking here.
            ->where('statistics.active_tenants', 1)
            ->where('statistics.total_tenants', 1)
            ->where('statistics.bookings_total', 1)
            ->where('statistics.signups_total', 1)
        );
});

it('leaves a demo tenant turnover out of the commission dashboard', function () {
    CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);

    $real = Tenant::factory()->active()->create();
    $demo = Tenant::factory()->demo()->create();

    TenantBillingPeriod::factory()->create([
        'tenant_id' => $real->id,
        'period' => '2026-07',
        'turnover_minor' => 1_000_000,
        'commission_minor' => 10_000,
    ]);
    // Seeded to dwarf the real tenant: if it leaked in, no assertion below
    // could pass by accident.
    TenantBillingPeriod::factory()->create([
        'tenant_id' => $demo->id,
        'period' => '2026-07',
        'turnover_minor' => 900_000_000,
        'commission_minor' => 9_000_000,
    ]);

    $this->actingAs(superAdmin())
        ->get(superUrl('/'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Dashboard')
            ->where('statistics.turnover_total_minor', 1_000_000)
            ->where('statistics.commission_accrued_minor', 10_000)
            ->where('statistics.active_tenants', 1)
            ->where('statistics.tenants_with_turnover', 1)
            // The demo persona would otherwise own the top-tenants table for good.
            ->count('statistics.top_tenants', 1)
            ->where('statistics.top_tenants.0.tenant_id', $real->id)
        );
});

// --- Superadmin surface ----------------------------------------------------

it('marks the demo tenants in the superadmin list and on the detail page', function () {
    $demo = Tenant::factory()->demo()->create(['name' => 'Premium Fitness Studio']);
    Tenant::factory()->active()->create(['name' => 'Igazi Ügyfél']);

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Tenants/Index')
            ->count('tenants.data', 2)
            // Newest first (orderByDesc id): the demo tenant was created first.
            ->where('tenants.data.0.is_demo', false)
            ->where('tenants.data.1.is_demo', true)
        );

    $this->actingAs(superAdmin())
        ->get(superUrl('/tenants/'.$demo->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Super/Tenants/Show')
            ->where('tenant.is_demo', true)
        );
});

it('refuses to make a tenant a demo one by mass assignment', function () {
    // The flag REMOVES restrictions no other tenant may remove — a demo tenant
    // is exempt from the billing close and vanishes from the platform figures.
    // Mass-assignable, it would be one stray request payload away from a paying
    // customer that quietly stops being invoiced.
    $tenant = new Tenant;
    $tenant->fill(['name' => 'Acme', 'slug' => 'acme', 'is_demo' => true]);

    expect($tenant->is_demo)->not->toBeTrue();
});
