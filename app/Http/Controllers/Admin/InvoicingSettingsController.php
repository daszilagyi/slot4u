<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InvoicingSettingsRequest;
use App\Services\Invoicing\Billingo\BillingoClient;
use App\Settings\TenantInvoicingSettings;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Where a tenant chooses who issues its invoices and hands over the credential
 * (SLO-167).
 *
 * Behind the invoicing feature flag AND settings.edit (routes/tenant.php).
 *
 * ⚠️ The API key travels one way only. It is never sent back to the browser —
 * the screen learns whether one is set, never what it is (the settings column is
 * encrypted at rest for the same reason). A "reveal" affordance would put a live
 * provider credential into an Inertia prop, and from there into any page cache
 * or error report that touches it.
 */
class InvoicingSettingsController extends Controller
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function index(): Response
    {
        $tenant = $this->tenants->current();
        $settings = TenantInvoicingSettings::fromArray($tenant?->invoicing);

        return Inertia::render('Admin/Settings/Invoicing', [
            'settings' => [
                'provider' => $settings->provider?->value,
                'hasApiKey' => $settings->hasApiKey(),
                'sellerName' => $settings->sellerName,
                'sellerTaxNumber' => $settings->sellerTaxNumber,
                'sellerAddress' => $settings->sellerAddress,
                'vatKey' => $settings->vatKey,
                'blockId' => $settings->blockId,
                'bankAccountId' => $settings->bankAccountId,
                'complete' => $settings->isComplete(),
            ],
            'providers' => array_map(
                fn (InvoiceProvider $provider): array => [
                    'value' => $provider->value,
                    'label' => __($provider->label()),
                ],
                InvoiceProvider::selectable(),
            ),
            // The blocks and accounts come from the tenant's OWN provider account,
            // so they are only knowable once a key is stored. Asking an admin to
            // type an id they would have to go and look up is how a wrong id ends
            // up on a live invoice.
            ...$this->providerOptions($settings),
        ]);
    }

    public function update(InvoicingSettingsRequest $request): RedirectResponse
    {
        $tenant = $this->tenants->current();

        if ($tenant === null) {
            abort(404);
        }

        $current = TenantInvoicingSettings::fromArray($tenant->invoicing);
        $data = $request->validated();

        $tenant->invoicing = (new TenantInvoicingSettings(
            provider: InvoiceProvider::tryFrom((string) ($data['provider'] ?? '')),
            // An empty key field means "leave it alone", not "erase it": the form
            // cannot show the stored value, so it cannot send it back either, and
            // treating a blank as a deletion would wipe the credential every time
            // someone edited the seller's address.
            apiKey: $this->str($data, 'api_key') ?? $current->apiKey,
            sellerName: $this->str($data, 'seller_name'),
            sellerTaxNumber: $this->str($data, 'seller_tax_number'),
            sellerAddress: $this->str($data, 'seller_address'),
            vatKey: $this->str($data, 'vat_key') ?? TenantInvoicingSettings::DEFAULT_VAT_KEY,
            blockId: isset($data['block_id']) && is_numeric($data['block_id']) ? (int) $data['block_id'] : null,
            bankAccountId: isset($data['bank_account_id']) && is_numeric($data['bank_account_id']) ? (int) $data['bank_account_id'] : null,
        ))->toArray();

        $tenant->save();

        return back()->with('status', __('app.invoicing.settings.saved'));
    }

    /**
     * The document blocks and bank accounts to choose from.
     *
     * A provider that will not answer is reported as a message rather than an
     * exception: a wrong or revoked key is the most likely reason to be on this
     * screen, and a 500 would tell the admin nothing about which field to fix.
     *
     * @return array<string, mixed>
     */
    private function providerOptions(TenantInvoicingSettings $settings): array
    {
        if ($settings->provider !== InvoiceProvider::Billingo || ! $settings->hasApiKey()) {
            return ['blocks' => [], 'bankAccounts' => [], 'providerError' => null];
        }

        $client = new BillingoClient((string) $settings->apiKey);

        try {
            return [
                'blocks' => $client->documentBlocks(),
                'bankAccounts' => $client->bankAccounts(),
                'providerError' => null,
            ];
        } catch (Throwable $e) {
            return ['blocks' => [], 'bankAccounts' => [], 'providerError' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
