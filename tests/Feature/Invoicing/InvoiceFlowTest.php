<?php

use App\Actions\Invoice\RecordInvoiceForPayment;
use App\Actions\Payment\RefundBookingPayments;
use App\Actions\Payment\SettleBookingPayment;
use App\Actions\Tenant\SetTenantFeature;
use App\Enums\BookingStatus;
use App\Enums\Feature;
use App\Enums\InvoiceProvider;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\IssueInvoice;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Services\Invoicing\Issuers\SandboxInvoiceIssuer;
use App\Tenancy\TenantManager;
use Database\Seeders\BasePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
 * Customer invoicing (SLO-41 / SLO-133): a settled payment is invoiced through the
 * provider abstraction, a full refund stornoes it, a provider refusal stays visible
 * and retryable, and the PDF only reaches the people entitled to it.
 */

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(BasePlanSeeder::class);
    Carbon::setTestNow('2026-09-01 12:00:00');
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

/** A tenant that invoices online (feature + seller details configured). */
function invTenant(string $slug = 'acme'): Tenant
{
    $tenant = Tenant::factory()->active()->create(['slug' => $slug]);
    $tenant->invoicing = ['api_key' => 'secret-agent-key', 'seller_name' => 'Acme Kft.'];
    $tenant->save();

    app(SetTenantFeature::class)($tenant, Feature::Invoicing, true);
    app(TenantManager::class)->set($tenant);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

/** A confirmed booking with one pending payment, ready to be settled. */
function invPayment(Tenant $tenant, ?User $customer = null, int $amountMinor = 250000): Payment
{
    $service = Service::factory()->forTenant($tenant)->create(['name' => 'Uszodabérlet']);

    $booking = Booking::factory()->forTenant($tenant)->status(BookingStatus::PendingPayment)->create([
        'service_id' => $service->id,
        'customer_id' => $customer?->getKey(),
        'price_minor' => $amountMinor,
        'starts_at' => Carbon::parse('2026-09-10 08:00:00'),
        'ends_at' => Carbon::parse('2026-09-10 09:00:00'),
    ]);

    return Payment::factory()->forBooking($booking)->create(['amount_minor' => $amountMinor]);
}

function invCustomer(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::Customer->value);

    return $user;
}

function invAdmin(Tenant $tenant): User
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());

    return $user;
}

/** Swap the sandbox issuer for one that always refuses. */
function failingIssuer(string $message = 'agent kulcs érvénytelen'): void
{
    app()->bind(InvoiceIssuerManager::class, fn () => new class($message) extends InvoiceIssuerManager
    {
        public function __construct(private readonly string $message)
        {
            parent::__construct(new SandboxInvoiceIssuer);
        }

        public function for(InvoiceProvider $provider): InvoiceIssuer
        {
            $message = $this->message;

            return new class($message) implements InvoiceIssuer
            {
                public function __construct(private readonly string $message) {}

                public function provider(): InvoiceProvider
                {
                    return InvoiceProvider::Sandbox;
                }

                public function issue(InvoiceRequest $request): IssuedInvoice
                {
                    throw new RuntimeException($this->message);
                }

                public function storno(Invoice $invoice): IssuedInvoice
                {
                    throw new RuntimeException($this->message);
                }
            };
        }
    });
}

it('issues an invoice with a PDF when a payment settles', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant, amountMinor: 250000);

    app(SettleBookingPayment::class)($payment);

    $invoice = Invoice::withoutGlobalScopes()->sole();
    expect($invoice->tenant_id)->toBe($tenant->id)
        ->and($invoice->payment_id)->toBe($payment->id)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toStartWith('SBX-')
        ->and($invoice->amount_minor)->toBe(250000)
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->pdf_path)->toStartWith("tenants/{$tenant->id}/invoices/");

    // The document lives on the PRIVATE disk, and it really is a PDF.
    Storage::disk('local')->assertExists($invoice->pdf_path);
    expect(Storage::disk('local')->get($invoice->pdf_path))->toStartWith('%PDF-');

    // The booking is confirmed as before — invoicing rides along, it does not gate.
    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('does not invoice a tenant without the invoicing integration', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme']);
    app(TenantManager::class)->set($tenant);
    $payment = invPayment($tenant);

    app(SettleBookingPayment::class)($payment);

    expect(Invoice::withoutGlobalScopes()->count())->toBe(0)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('invoices a payment only once, however often the callback repeats', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);

    app(SettleBookingPayment::class)($payment);
    app(SettleBookingPayment::class)($payment->fresh());
    app(RecordInvoiceForPayment::class)($payment->fresh());

    expect(Invoice::withoutGlobalScopes()->count())->toBe(1);
});

it('stornoes the invoice when the payment is refunded in full', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant, amountMinor: 100000);
    app(SettleBookingPayment::class)($payment);

    $invoice = Invoice::withoutGlobalScopes()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Issued);

    app(RefundBookingPayments::class)($payment->booking, RefundBookingPayments::FULL_REFUND, 'lemondás');

    $invoice = $invoice->fresh();
    expect($invoice->status)->toBe(InvoiceStatus::Storno)
        ->and($invoice->storno_number)->toStartWith('SBX-ST-')
        ->and($invoice->stornoed_at)->not->toBeNull()
        ->and($invoice->storno_pdf_path)->not->toBeNull();

    Storage::disk('local')->assertExists($invoice->storno_pdf_path);
});

it('leaves the invoice live for a partial refund', function () {
    // A partial refund would need a corrective invoice, which no adapter issues —
    // the original must stay valid rather than be voided wholesale.
    $tenant = invTenant();
    $payment = invPayment($tenant, amountMinor: 100000);
    app(SettleBookingPayment::class)($payment);

    app(RefundBookingPayments::class)($payment->booking, 30000, 'részleges');

    expect(Invoice::withoutGlobalScopes()->sole()->status)->toBe(InvoiceStatus::Issued)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

it('keeps a refused invoice visible and retryable', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);

    failingIssuer('agent kulcs érvénytelen');
    app(SettleBookingPayment::class)($payment);

    $invoice = Invoice::withoutGlobalScopes()->sole();
    expect($invoice->status)->toBe(InvoiceStatus::Failed)
        ->and($invoice->error)->toContain('agent kulcs érvénytelen')
        ->and($invoice->number)->toBeNull();

    // The admin retries once the provider is back — the issuer is healthy again.
    app()->forgetInstance(InvoiceIssuerManager::class);
    app()->bind(InvoiceIssuerManager::class, fn () => new InvoiceIssuerManager(new SandboxInvoiceIssuer));

    $admin = invAdmin($tenant);
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$invoice->booking_id}/invoices/{$invoice->id}/retry"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->fresh()->error)->toBeNull();
});

it('refuses to retry an invoice that is already issued', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);
    app(SettleBookingPayment::class)($payment);
    $invoice = Invoice::withoutGlobalScopes()->sole();

    $admin = invAdmin($tenant);
    $this->actingAs($admin)
        ->post(tenantHost('acme', "/bookings/{$invoice->booking_id}/invoices/{$invoice->id}/retry"))
        ->assertSessionHasErrors('invoice');

    // Still exactly one document — no second invoice was minted.
    expect(Invoice::withoutGlobalScopes()->sole()->number)->toBe($invoice->number);
});

it('never re-issues an already issued invoice, even if the job runs again', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);
    app(SettleBookingPayment::class)($payment);

    $invoice = Invoice::withoutGlobalScopes()->sole();
    $number = $invoice->number;

    app(IssueInvoice::class, ['invoiceId' => $invoice->id])->handle(app(InvoiceIssuerManager::class));

    expect($invoice->fresh()->number)->toBe($number);
});

it('shows the invoice to the admin and serves its PDF', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);
    app(SettleBookingPayment::class)($payment);
    $invoice = Invoice::withoutGlobalScopes()->sole();

    $admin = invAdmin($tenant);

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$invoice->booking_id}"))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invoice.number', $invoice->number)
            ->where('invoice.status', InvoiceStatus::Issued->value)
            ->where('invoice.has_pdf', true)
        );

    $this->actingAs($admin)
        ->get(tenantHost('acme', "/bookings/{$invoice->booking_id}/invoices/{$invoice->id}/pdf"))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=szamla-'.$invoice->number.'.pdf');
});

it('lists the customer\'s own invoices and lets them download the PDF', function () {
    $tenant = invTenant();
    $me = invCustomer($tenant);
    $payment = invPayment($tenant, $me);
    app(SettleBookingPayment::class)($payment);
    $invoice = Invoice::withoutGlobalScopes()->sole();

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/invoices'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/My/Invoices')
            ->has('invoices', 1)
            ->where('invoices.0.number', $invoice->number)
            ->where('invoices.0.has_pdf', true)
        );

    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/invoices/{$invoice->id}/pdf"))
        ->assertOk();
});

it('hides another customer\'s invoice and its PDF', function () {
    $tenant = invTenant();
    $me = invCustomer($tenant);
    $other = invCustomer($tenant);

    $payment = invPayment($tenant, $other);
    app(SettleBookingPayment::class)($payment);
    $invoice = Invoice::withoutGlobalScopes()->sole();

    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/invoices'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('invoices', 0));

    // The document itself is out of reach too — 404, like a cross-tenant id.
    $this->actingAs($me)
        ->get(tenantHost('acme', "/my/invoices/{$invoice->id}/pdf"))
        ->assertNotFound();
});

it('keeps a not-yet-issued invoice out of the customer\'s list', function () {
    $tenant = invTenant();
    $me = invCustomer($tenant);
    $payment = invPayment($tenant, $me);

    failingIssuer();
    app(SettleBookingPayment::class)($payment);

    // A failed invoice is the tenant's problem to fix, not a document to promise.
    $this->actingAs($me)
        ->get(tenantHost('acme', '/my/invoices'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('invoices', 0));
});

it('isolates invoices between tenants', function () {
    $tenant = invTenant();
    $payment = invPayment($tenant);
    app(SettleBookingPayment::class)($payment);

    $other = Tenant::factory()->active()->create(['slug' => 'other']);
    app(TenantManager::class)->set($other);

    expect(Invoice::query()->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('stores the invoicing credential encrypted at rest', function () {
    $tenant = invTenant();

    $raw = (string) DB::table('tenants')->where('id', $tenant->id)->value('invoicing');

    expect($raw)->not->toContain('secret-agent-key')
        ->and($tenant->fresh()->invoicing['api_key'])->toBe('secret-agent-key');
});
