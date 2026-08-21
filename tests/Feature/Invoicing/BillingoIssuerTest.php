<?php

use App\Enums\InvoiceProvider;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\InvoicingPartner;
use App\Models\Tenant;
use App\Services\Invoicing\BillingDetails;
use App\Services\Invoicing\Billingo\BillingoClient;
use App\Services\Invoicing\InvoiceIssuerManager;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\Issuers\BillingoInvoiceIssuer;
use App\Settings\TenantInvoicingSettings;
use App\Tenancy\TenantManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The Billingo adapter (SLO-167)
|--------------------------------------------------------------------------
|
| Every request here is faked: the suite must never reach Billingo, both because
| a test that needs the network is not a test and because a real call would issue
| a real document in somebody's account.
|
| The shapes the fakes return were taken from live responses, not invented — an
| adapter tested only against a fake of its author's imagination agrees with
| itself and with nothing else.
|
*/

afterEach(function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(TenantManager::class)->forget();
});

function billingoTenant(array $invoicing = []): Tenant
{
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'invoicing' => array_merge([
            'provider' => 'billingo',
            'api_key' => 'test-key',
            'seller_name' => 'Acme Kft.',
            'vat_key' => '27',
            'block_id' => 329303,
            'receipt_block_id' => 440404,
        ], $invoicing),
    ]);

    app(TenantManager::class)->set($tenant);

    return $tenant;
}

function billingoSeller(Tenant $tenant): TenantInvoicingSettings
{
    return TenantInvoicingSettings::fromArray($tenant->invoicing);
}

/**
 * The DEFAULT path: no invoice asked for, so a receipt is issued (SLO-168).
 */
function billingoRequest(
    Tenant $tenant,
    int $amountMinor = 1_270_000,
    ?string $email = 'vevo@example.test',
    ?BillingDetails $billing = null,
): InvoiceRequest {
    return new InvoiceRequest(
        seller: billingoSeller($tenant),
        buyerName: 'Teszt Vevő',
        buyerEmail: $email,
        itemName: 'Hajvágás',
        amountMinor: $amountMinor,
        currency: 'HUF',
        issueDate: '2026-08-21',
        billing: $billing ?? new BillingDetails,
    );
}

/**
 * A buyer who asked for an invoice and gave a full address.
 *
 * ⚠️ `array_key_exists`, not `??`: passing `['city' => null]` to blank a field is
 * exactly what the incomplete-address test needs, and `??` would silently hand
 * back the default — making that test assert the opposite of what it claims.
 */
function billingoInvoiceDetails(array $overrides = []): BillingDetails
{
    $value = fn (string $key, mixed $default): mixed => array_key_exists($key, $overrides)
        ? $overrides[$key]
        : $default;

    return new BillingDetails(
        wantsInvoice: true,
        name: $value('name', 'Teszt Vevő'),
        taxNumber: $value('taxNumber', null),
        countryCode: $value('countryCode', null),
        postCode: $value('postCode', '1051'),
        city: $value('city', 'Budapest'),
        address: $value('address', 'Példa utca 1.'),
    );
}

/** The invoice path: an invoice asked for, with everything it needs. */
function billingoInvoiceRequest(Tenant $tenant, int $amountMinor = 1_270_000, ?string $email = 'vevo@example.test'): InvoiceRequest
{
    return billingoRequest($tenant, $amountMinor, $email, billingoInvoiceDetails());
}

/**
 * The happy path, in the shapes the live API actually returns.
 *
 * ⚠️ Overrides go FIRST, with the union operator rather than array_merge: Http
 * fakes match on the first pattern that fits, so a specific `/partners/999` must
 * be listed ahead of the `/partners/*` wildcard or the wildcard answers for it.
 */
function fakeBillingo(array $overrides = []): void
{
    Http::fake($overrides + [
        BillingoClient::BASE_URL.'/partners/*' => Http::response(['id' => 1963290060], 200),
        BillingoClient::BASE_URL.'/partners' => Http::response(['id' => 1963290060], 201),
        BillingoClient::BASE_URL.'/documents/receipt' => Http::response([
            'id' => 134700001,
            'invoice_number' => 'NY-2026-1',
            'type' => 'receipt',
            'gross_total' => 12700,
            'currency' => 'HUF',
        ], 201),
        BillingoClient::BASE_URL.'/documents' => Http::response([
            'id' => 134638151,
            'invoice_number' => '2026-1',
            'type' => 'invoice',
            'gross_total' => 12700,
            'currency' => 'HUF',
        ], 201),
        BillingoClient::BASE_URL.'/documents/*/cancel' => Http::response([
            'id' => 134638184,
            'invoice_number' => '2026-2',
            'type' => 'cancellation',
            'gross_total' => -12700,
        ], 200),
        BillingoClient::BASE_URL.'/documents/*/download' => Http::response('%PDF-1.4 fake', 200),
    ]);
}

/** The JSON body of the first request to a path fragment. */
function sentBody(string $fragment): array
{
    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        if (str_contains($request->url(), $fragment) && $request->method() === 'POST') {
            return $request->data();
        }
    }

    return [];
}

it('⚠️ sends whole forints, not fillér — a hundredfold invoice is the failure this guards', function () {
    // Our amounts are minor units (docs/01 §6); Billingo counts in whole ones.
    // 1 270 000 fillér is 12 700 Ft, and sending the stored value verbatim would
    // invoice 1 270 000 Ft. Pinned on its own because a fake that repeated the
    // mistake would agree with the bug.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant, 1_270_000));

    expect(sentBody('/documents')['items'][0]['unit_price'])->toBe(12700);
});

it('keeps a remainder rather than rounding a legal document', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant, 1_270_050));

    expect(sentBody('/documents')['items'][0]['unit_price'])->toBe(12700.5);
});

it('declares the price as gross, because that is what the customer paid', function () {
    // Declared as net, Billingo would add VAT on top of a price that already
    // includes it, and the invoice would not match the payment.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    $item = sentBody('/documents')['items'][0];

    expect($item['unit_price_type'])->toBe('gross')
        ->and($item['vat'])->toBe('27%');
});

it('returns the number, the document id and the PDF', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    $issued = app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect($issued->number)->toBe('2026-1')
        ->and($issued->providerRef)->toBe('134638151')
        ->and($issued->pdf)->toStartWith('%PDF');
});

it('names the tenant document block on the invoice', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect(sentBody('/documents')['block_id'])->toBe(329303);
});

it('omits the bank account when the tenant has none, rather than sending null', function () {
    $tenant = billingoTenant(['bank_account_id' => null]);
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect(sentBody('/documents'))->not->toHaveKey('bank_account_id');
});

it('creates a partner on the first invoice and remembers it', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    $partner = InvoicingPartner::withoutGlobalScopes()->sole();

    expect($partner->tenant_id)->toBe($tenant->id)
        ->and($partner->provider)->toBe(InvoiceProvider::Billingo)
        ->and($partner->email)->toBe('vevo@example.test')
        ->and($partner->partner_ref)->toBe('1963290060');
});

it('reuses the remembered partner instead of creating a duplicate', function () {
    // Billingo cannot find a partner by email (its query matches names), so
    // without the mapping every invoice would add another copy of the same
    // customer to the tenant's account.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));
    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    $created = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/partners'))
        ->count();

    expect($created)->toBe(1)
        ->and(InvoicingPartner::withoutGlobalScopes()->count())->toBe(1);
});

it('creates a new partner when the remembered one is gone from Billingo', function () {
    // The tenant can delete a partner in its own account; a stale mapping would
    // otherwise fail every future invoice for that customer.
    $tenant = billingoTenant();
    InvoicingPartner::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'provider' => InvoiceProvider::Billingo->value,
        'email' => 'vevo@example.test',
        'partner_ref' => '999',
    ]);

    fakeBillingo([BillingoClient::BASE_URL.'/partners/999' => Http::response(['message' => 'Not Found'], 404)]);

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect(InvoicingPartner::withoutGlobalScopes()->sole()->partner_ref)->toBe('1963290060');
});

it('does not try to remember a buyer with no email', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant, email: null, billing: billingoInvoiceDetails()));

    expect(InvoicingPartner::withoutGlobalScopes()->count())->toBe(0);
});

it('keeps one tenant partner mapping away from another', function () {
    $tenant = billingoTenant();
    $other = Tenant::factory()->active()->create(['slug' => 'other']);

    // ⚠️ The tenant must be BOUND while the foreign row is created: the
    // BelongsToTenant creating hook stamps the current tenant over whatever
    // tenant_id is passed, and withoutGlobalScopes() only lifts the read scope.
    // Without this the "foreign" partner lands on acme and the test proves the
    // opposite of what it claims.
    app(TenantManager::class)->set($other);
    InvoicingPartner::create([
        'provider' => InvoiceProvider::Billingo->value,
        'email' => 'vevo@example.test',
        'partner_ref' => '111111',
    ]);

    fakeBillingo();
    app(TenantManager::class)->set($tenant);

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    // The other tenant's partner id must never be used here: it belongs to a
    // different Billingo account entirely.
    expect(sentBody('/documents')['partner_id'])->toBe(1963290060);
});

it('voids through the cancel endpoint and returns the cancellation document', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    $invoice = new Invoice([
        'provider' => InvoiceProvider::Billingo,
        'amount_minor' => 1_270_000,
        'currency' => 'HUF',
    ]);
    $invoice->provider_ref = '134638151';
    $invoice->number = '2026-1';

    $storno = app(BillingoInvoiceIssuer::class)->storno($invoice, billingoSeller($tenant));

    expect($storno->number)->toBe('2026-2')
        ->and($storno->providerRef)->toBe('134638184');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/documents/134638151/cancel'));
});

it('carries Billingo own words into the failure, not a generic message', function () {
    // The message ends up on the invoice row and is the only thing an admin can
    // act on. "Invoicing failed" would make a revoked key and a deleted block
    // look identical.
    $tenant = billingoTenant();
    fakeBillingo([
        BillingoClient::BASE_URL.'/documents' => Http::response([
            'message' => 'Validation Failed',
            'errors' => [['field' => 'block_id', 'message' => 'The selected block id is invalid.']],
        ], 422),
    ]);

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant)))
        ->toThrow(RuntimeException::class, 'block_id');
});

it('refuses before calling anything when no key is configured', function () {
    $tenant = billingoTenant(['api_key' => null]);
    Http::fake();

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant)))
        ->toThrow(RuntimeException::class, 'API key');

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'billingo'))->count())->toBe(0);
});

it('refuses before calling anything when no document block is chosen', function () {
    $tenant = billingoTenant(['block_id' => null]);
    Http::fake();

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant)))
        ->toThrow(RuntimeException::class, 'document block');

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'billingo'))->count())->toBe(0);
});

it('lets the tenant choice beat the platform default', function () {
    config()->set('invoicing.default', 'sandbox');
    $tenant = billingoTenant();

    expect(app(InvoiceIssuerManager::class)->forTenant($tenant)->provider())
        ->toBe(InvoiceProvider::Billingo);
});

it('falls back to the platform default for a tenant that chose nothing', function () {
    config()->set('invoicing.default', 'sandbox');
    $tenant = Tenant::factory()->active()->create(['slug' => 'acme', 'invoicing' => null]);

    expect(app(InvoiceIssuerManager::class)->forTenant($tenant)->provider())
        ->toBe(InvoiceProvider::Sandbox);
});

it('refuses Számlázz.hu by name rather than quietly issuing something else', function () {
    // Falling back to the sandbox would produce a document with no legal
    // standing and report success — worse than any failure.
    expect(fn () => app(InvoiceIssuerManager::class)->for(InvoiceProvider::SzamlazzHu))
        ->toThrow(RuntimeException::class, 'szamlazzhu');
});

it('does not offer Számlázz.hu as a choice while it has no adapter', function () {
    expect(InvoiceProvider::selectable())->toBe([InvoiceProvider::Billingo])
        ->and(InvoiceProvider::SzamlazzHu->isImplemented())->toBeFalse()
        ->and(InvoiceProvider::Billingo->isImplemented())->toBeTrue();
});

it('knows when a tenant invoicing setup is incomplete', function () {
    expect(TenantInvoicingSettings::fromArray(['provider' => 'billingo', 'api_key' => 'k', 'block_id' => 1])->isComplete())->toBeTrue()
        ->and(TenantInvoicingSettings::fromArray(['provider' => 'billingo', 'api_key' => 'k'])->isComplete())->toBeFalse()
        ->and(TenantInvoicingSettings::fromArray(['provider' => 'billingo', 'block_id' => 1])->isComplete())->toBeFalse()
        ->and(TenantInvoicingSettings::fromArray([])->isComplete())->toBeFalse();
});

it('never lets an invoice row escape without a provider that can be resolved later', function () {
    // A row outlives the driver it was issued with; the enum keeps every value
    // an old row may carry.
    $tenant = billingoTenant();
    fakeBillingo();

    $issued = app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    $invoice = new Invoice([
        'provider' => InvoiceProvider::Billingo,
        'amount_minor' => 1_270_000,
        'currency' => 'HUF',
        'status' => InvoiceStatus::Issued,
    ]);
    $invoice->provider_ref = $issued->providerRef;

    expect(app(InvoiceIssuerManager::class)->for($invoice->provider))
        ->toBeInstanceOf(BillingoInvoiceIssuer::class);
});

/*
|--------------------------------------------------------------------------
| Receipt by default, invoice on request (SLO-168)
|--------------------------------------------------------------------------
|
| The default path exists because the Áfa tv. 169. § e) makes the buyer's
| address mandatory on an INVOICE and on nothing else. A receipt is legally
| sufficient for a private individual paying by card, and asking everyone for an
| address would collect personal data the transaction does not need.
|
*/

it('issues a receipt when nobody asked for an invoice', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    $issued = app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant));

    expect($issued->number)->toBe('NY-2026-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/documents/receipt'));
});

it('needs no address and no partner for a receipt', function () {
    // The whole point: this is the path that works with what a booking already
    // collects — a name and an email.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant));

    $body = sentBody('/documents/receipt');

    expect($body)->not->toHaveKey('partner_id')
        ->and($body['name'])->toBe('Teszt Vevő')
        ->and($body['emails'])->toBe(['vevo@example.test']);

    expect(collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/partners'))
        ->count())->toBe(0);
});

it('writes a receipt into the receipt block, not the invoice one', function () {
    // Billingo numbers receipts separately; using the invoice block would either
    // be refused or, worse, mis-number a real invoice series.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant));

    expect(sentBody('/documents/receipt')['block_id'])->toBe(440404);
});

it('issues an invoice when one was asked for with a full address', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    $issued = app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect($issued->number)->toBe('2026-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/documents'));
});

it('sends the address Billingo refused to do without', function () {
    // The 422 that started SLO-168: post_code, city and address are all required
    // on a partner, and the OpenAPI document lists none of them as such.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoInvoiceRequest($tenant));

    expect(sentBody('/partners')['address'])->toBe([
        'country_code' => 'HU',
        'post_code' => '1051',
        'city' => 'Budapest',
        'address' => 'Példa utca 1.',
    ]);
});

it('marks a buyer with a tax number as a business', function () {
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoRequest(
        $tenant,
        billing: billingoInvoiceDetails(['taxNumber' => '12345678-2-42']),
    ));

    $body = sentBody('/partners');

    expect($body['taxcode'])->toBe('12345678-2-42')
        ->and($body['tax_type'])->toBe('HAS_TAX_NUMBER');
});

it('falls back to a receipt when an invoice was asked for with half an address', function () {
    // A refused transaction would be worse: the customer has paid, and the
    // document they get is a legally valid one — just not the one they wanted.
    // The booking still records that they asked, so an admin can see it.
    $tenant = billingoTenant();
    fakeBillingo();

    app(BillingoInvoiceIssuer::class)->issue(billingoRequest(
        $tenant,
        billing: billingoInvoiceDetails(['city' => null]),
    ));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/documents/receipt'));
});

it('refuses before calling anything when no receipt block is configured', function () {
    $tenant = billingoTenant(['receipt_block_id' => null]);
    Http::fake();

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant)))
        ->toThrow(RuntimeException::class, 'receipt block');

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'billingo'))->count())->toBe(0);
});

it('reads what the buyer asked for off the booking, not off their profile', function () {
    // An issued document records what was true then. Reading a live profile
    // would let an address change rewrite history.
    $tenant = billingoTenant();
    $booking = Booking::factory()->forTenant($tenant)->create([
        'wants_invoice' => true,
        'billing_name' => 'Céges Vevő Kft.',
        'billing_post_code' => '1051',
        'billing_city' => 'Budapest',
        'billing_address' => 'Példa utca 1.',
    ]);

    $billing = BillingDetails::fromBooking($booking);

    expect($billing->canInvoice())->toBeTrue()
        ->and($billing->name)->toBe('Céges Vevő Kft.')
        ->and($billing->country())->toBe('HU');
});

it('treats a booking with no billing details as a receipt', function () {
    $tenant = billingoTenant();
    $booking = Booking::factory()->forTenant($tenant)->create();

    expect(BillingDetails::fromBooking($booking)->canInvoice())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The PDF is not ready the moment the document is (SLO-168)
|--------------------------------------------------------------------------
|
| ⚠️ Billingo renders asynchronously and answers an early download with HTTP 202
| and a 59-byte JSON error. 202 is a 2xx, so every "was it successful?" check
| says yes — and the adapter stored that JSON as the customer's invoice. Nothing
| looked wrong until somebody clicked download.
|
| Found by a live run against the demo account, because a fake that returns a
| PDF instantly cannot express the problem. These tests exist so the fix is held
| in place now that it is known.
|
*/

it('waits for a PDF that is still being rendered', function () {
    $tenant = billingoTenant();
    fakeBillingo([
        BillingoClient::BASE_URL.'/documents/*/download' => Http::sequence()
            ->push('{"error":{"message":"Document PDF has not generated yet."}}', 202)
            ->push('{"error":{"message":"Document PDF has not generated yet."}}', 202)
            ->push('%PDF-1.4 rendered at last', 200),
    ]);

    $issued = app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant));

    expect($issued->pdf)->toStartWith('%PDF');
});

it('never stores a 202 body as the document', function () {
    // The exact failure: a successful status carrying an error, saved as a PDF.
    $tenant = billingoTenant();
    fakeBillingo([
        BillingoClient::BASE_URL.'/documents/*/download' => Http::response(
            '{"error":{"message":"Document PDF has not generated yet."}}',
            202,
        ),
    ]);

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant)))
        ->toThrow(RuntimeException::class, 'has not finished rendering');
});

it('refuses a 200 that is not a PDF either', function () {
    // Belt and braces on the same idea: the status is only half the question,
    // and the other half is whether the bytes are a document at all.
    $tenant = billingoTenant();
    fakeBillingo([
        BillingoClient::BASE_URL.'/documents/*/download' => Http::response('<html>maintenance</html>', 200),
    ]);

    expect(fn () => app(BillingoInvoiceIssuer::class)->issue(billingoRequest($tenant)))
        ->toThrow(RuntimeException::class, 'has not finished rendering');
});
