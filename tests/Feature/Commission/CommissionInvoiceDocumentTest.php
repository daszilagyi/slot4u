<?php

use App\Actions\Commission\GenerateCommissionInvoice;
use App\Actions\Commission\VoidCommissionInvoice;
use App\Enums\CommissionInvoiceStatus;
use App\Enums\CommissionItemState;
use App\Enums\InvoiceProvider;
use App\Enums\Role;
use App\Events\BookingCreated;
use App\Events\BookingStatusChanged;
use App\Jobs\IssueCommissionInvoiceDocument;
use App\Jobs\StornoCommissionInvoiceDocument;
use App\Models\BookingCommissionItem;
use App\Models\CommissionInvoice;
use App\Models\CommissionSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Services\Invoicing\Issuers\BillingoInvoiceIssuer;
use App\Services\Invoicing\Issuers\SandboxInvoiceIssuer;
use App\Services\Invoicing\PlatformInvoicing;
use App\Services\Invoicing\StornoRequest;
use App\Tenancy\TenantManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| slot4u's own document for its commission invoice (SLO-143)
|--------------------------------------------------------------------------
|
| The commission engine has issued rows since J6, but docs/10 §6.5 step 4 —
| "optionally issue an external invoice" — never landed. `pdf_path` was always
| null, so `Admin\BillingController::invoicePdf` 404'd by design and a tenant
| could not obtain the invoice for a cost it had already paid.
|
| The property these tests defend hardest is the separation between the DEBT
| and the PAPERWORK. A provider outage on the first of the month must not stop
| slot4u being owed money, must not stop the reminder, and must not make the
| invoice unsettleable — and, in the other direction, one debt must never
| produce two documents.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-07-05 09:00:00');
    $this->seed(PermissionSeeder::class);
    Event::fake([BookingCreated::class, BookingStatusChanged::class]);
    Storage::fake((string) config('invoicing.disk'));

    // The test queue runs jobs inline, which would issue the document during the
    // monthly close and leave nothing for the tests below to drive. Faking it
    // also matches production, where the point of the job is that the close does
    // NOT wait on it.
    Queue::fake();

    $this->setting = CommissionSetting::factory()->create([
        'free_threshold_minor' => 0,
        'rate_bps' => 100,
        'rate_with_integration_bps' => 150,
        'monthly_cap_minor' => null,
        'currency' => 'HUF',
        'effective_from' => Carbon::parse('2026-01-01'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function documentBillable(Tenant $tenant, string $period, int $amount): BookingCommissionItem
{
    return BookingCommissionItem::factory()->create([
        'tenant_id' => $tenant->id,
        'period' => $period,
        'amount_minor' => $amount,
        'rate_bps' => 100,
        'realized_at' => Carbon::parse($period.'-10 12:00:00'),
        'state' => CommissionItemState::Billable,
        'settings_id' => test()->setting->id,
    ]);
}

/** A closed period with a real invoice behind it. */
function invoicedPeriod(int $amount = 1_000_000): CommissionInvoice
{
    $tenant = Tenant::factory()->active()->create(['name' => 'Acme Stúdió']);
    documentBillable($tenant, '2026-06', $amount);

    $invoice = app(GenerateCommissionInvoice::class)($tenant->id, '2026-06');

    expect($invoice)->not->toBeNull();

    return $invoice;
}

/**
 * An issuer that refuses everything.
 *
 * Swapped at the MANAGER, not at the sandbox binding: the manager's constructor
 * is typed on the concrete `SandboxInvoiceIssuer`, so rebinding that would blow
 * up before the refusal could be exercised. Same shape as InvoiceFlowTest's.
 */
function refusingIssuer(string $message = 'Invalid API key.'): void
{
    app()->forgetInstance(InvoiceIssuerManager::class);
    app()->bind(InvoiceIssuerManager::class, fn () => new class($message) extends InvoiceIssuerManager
    {
        public function __construct(private readonly string $message)
        {
            parent::__construct(new SandboxInvoiceIssuer, app(BillingoInvoiceIssuer::class));
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

                public function storno(StornoRequest $request): IssuedInvoice
                {
                    throw new RuntimeException($this->message);
                }
            };
        }
    });
}

/** The platform invoicing service, rebuilt against whatever manager is bound now. */
function platformInvoicing(): PlatformInvoicing
{
    return new PlatformInvoicing(app(InvoiceIssuerManager::class));
}

// --- The happy path ---

it('issues a document for the invoice and stores its PDF privately', function () {
    $invoice = invoicedPeriod();

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $fresh = $invoice->fresh();

    expect($fresh->provider_ref)->not->toBeNull()
        ->and($fresh->number)->not->toBeNull()
        ->and($fresh->provider)->toBe(InvoiceProvider::Sandbox->value)
        ->and($fresh->provider_error)->toBeNull()
        ->and($fresh->pdf_path)->not->toBeNull();

    // The private disk, under the tenant's prefix — it names a company and what
    // it owes, and the only way to it is the authorised download route.
    Storage::disk((string) config('invoicing.disk'))->assertExists($fresh->pdf_path);
    expect($fresh->pdf_path)->toStartWith("tenants/{$invoice->tenant_id}/commission/");
});

it('invoices the NET amount, leaving the VAT to the provider', function () {
    // 1% of 1,000,000 = 10,000 net; the row also carries 2,700 VAT. Sending the
    // gross would charge the tenant VAT twice — once in our arithmetic and once
    // in the provider's.
    $invoice = invoicedPeriod();

    expect($invoice->commission_net_minor)->toBe(10_000)
        ->and($invoice->vat_minor)->toBe(2_700);

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $pdf = Storage::disk((string) config('invoicing.disk'))->get($invoice->fresh()->pdf_path);

    expect($pdf)->toContain('100.00')->not->toContain('127.00');
});

it('queues the document from the monthly close, without waiting on it', function () {
    $invoice = invoicedPeriod();

    // The debt, the closed period and the tenant's email are all already done;
    // the document is the only part that goes to a queue.
    Queue::assertPushed(IssueCommissionInvoiceDocument::class, 1);
    expect($invoice->status)->toBe(CommissionInvoiceStatus::Issued);
});

// --- The debt does not depend on the paperwork ---

it('leaves the invoice owed and collectable when the provider refuses', function () {
    // A provider outage on the first of the month must not stop slot4u from
    // being owed money — and must not stop the reminder either.
    $invoice = invoicedPeriod();
    refusingIssuer('Invalid API key.');

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe(CommissionInvoiceStatus::Issued)
        ->and($fresh->status->isOutstanding())->toBeTrue()
        ->and($fresh->provider_ref)->toBeNull()
        ->and($fresh->pdf_path)->toBeNull()
        // Recorded on the row, not only in a log: the superadmin list reads this,
        // so a provider that started refusing is visible on the first of the
        // month rather than whenever somebody notices no documents since spring.
        ->and($fresh->provider_error)->toContain('Invalid API key.');
});

it('never mints a second document for one debt', function () {
    // The one mistake an invoicing integration must not make: a numbering series
    // has no gaps to give back.
    $invoice = invoicedPeriod();

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());
    $first = $invoice->fresh()->number;

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    expect($invoice->fresh()->number)->toBe($first);
});

// --- Void ---

it('writes the storno as a second document, never over the first', function () {
    // The original invoice went to the tenant and into slot4u's books. An
    // accounting record you can silently replace is not one.
    $invoice = invoicedPeriod();
    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $originalPdf = $invoice->fresh()->pdf_path;

    app(VoidCommissionInvoice::class)($invoice->fresh());
    (new StornoCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe(CommissionInvoiceStatus::Void)
        ->and($fresh->pdf_path)->toBe($originalPdf)
        ->and($fresh->storno_ref)->not->toBeNull()
        ->and($fresh->storno_pdf_path)->not->toBeNull()
        ->and($fresh->storno_pdf_path)->not->toBe($originalPdf);

    Storage::disk((string) config('invoicing.disk'))->assertExists($fresh->pdf_path);
    Storage::disk((string) config('invoicing.disk'))->assertExists($fresh->storno_pdf_path);
});

it('does not try to void a document that was never issued', function () {
    // The month closed while the provider was down, or before the channel was
    // configured. Nothing at the provider to cancel — not an error.
    $invoice = invoicedPeriod();

    app(VoidCommissionInvoice::class)($invoice->fresh());
    (new StornoCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    expect($invoice->fresh()->storno_ref)->toBeNull();
});

it('keeps the invoice voided even if the storno is refused', function () {
    // The debt is already cancelled here. A provider refusing the storno leaves
    // paperwork to finish, not a tenant still owing money.
    $invoice = invoicedPeriod();
    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    refusingIssuer('Document already cancelled.');
    app(VoidCommissionInvoice::class)($invoice->fresh());
    (new StornoCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe(CommissionInvoiceStatus::Void)
        ->and($fresh->storno_ref)->toBeNull()
        ->and($fresh->provider_error)->toContain('Document already cancelled.');
});

// --- What the tenant finally gets ---

it('lets the tenant download the invoice it is being charged for', function () {
    // The whole point of the issue: until this, `pdf_path` was always null and
    // this endpoint 404'd by design.
    $invoice = invoicedPeriod();
    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $tenant = Tenant::withoutGlobalScopes()->findOrFail($invoice->tenant_id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    $admin = User::factory()->create(['tenant_id' => $tenant->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    $this->actingAs($admin)
        ->get(tenantHost($tenant->slug, '/billing/invoices/'.$invoice->id.'/pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('will not hand one tenant another tenant commission invoice', function () {
    $invoice = invoicedPeriod();
    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $other = Tenant::factory()->active()->create(['slug' => 'other-co']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($other->getKey());
    $admin = User::factory()->create(['tenant_id' => $other->id]);
    $admin->assignRole(Role::TenantAdmin->value);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();

    // A cross-tenant id is a 404, never a 403 (docs/01 §1).
    $this->actingAs($admin)
        ->get(tenantHost('other-co', '/billing/invoices/'.$invoice->id.'/pdf'))
        ->assertNotFound();
});

// --- Configuration ---

it('reports the sandbox channel as not live', function () {
    // The superadmin screen reads this to warn that the documents have no legal
    // standing. Assuming it silently would be the most expensive kind of quiet.
    expect(app(PlatformInvoicing::class)->isLive())->toBeFalse();
});

it('bills through the platform account, never the tenant own credential', function () {
    config()->set('invoicing.platform.seller_name', 'slot4u Kft.');
    config()->set('invoicing.platform.seller_tax_number', '12345678-2-42');

    $invoice = invoicedPeriod();
    // The tenant's own invoicing settings name somebody else entirely; billing a
    // tenant with that tenant's key would be wrong on its face — and would let
    // one tenant's broken configuration stop slot4u from invoicing it.
    Tenant::withoutGlobalScopes()->where('id', $invoice->tenant_id)->first()
        ?->forceFill(['invoicing' => ['provider' => 'sandbox', 'seller_name' => 'A tenant sajat cege']])
        ->save();

    (new IssueCommissionInvoiceDocument($invoice->id))->handle(platformInvoicing());

    $pdf = Storage::disk((string) config('invoicing.disk'))->get($invoice->fresh()->pdf_path);

    expect($pdf)->toContain('slot4u Kft.')
        ->not->toContain('A tenant sajat cege')
        // The tenant is the BUYER on this one invoice.
        ->and($pdf)->toContain('Acme');
});
