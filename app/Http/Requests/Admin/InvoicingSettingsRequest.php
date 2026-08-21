<?php

namespace App\Http\Requests\Admin;

use App\Enums\InvoiceProvider;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The tenant's invoicing configuration (SLO-167).
 *
 * Authorisation is the route's (`ensure.feature:feature_invoicing` +
 * `can:settings.edit`).
 */
class InvoicingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only a provider with an adapter behind it: the enum still carries
            // szamlazzhu so old invoice rows can name it, but choosing it would
            // configure a tenant into a service that cannot issue anything.
            'provider' => ['nullable', Rule::in(array_map(
                static fn (InvoiceProvider $provider): string => $provider->value,
                InvoiceProvider::selectable(),
            ))],
            // Blank means "keep the stored key" — the form never receives it, so
            // it cannot send it back (see the controller).
            'api_key' => ['nullable', 'string', 'max:255'],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'seller_tax_number' => ['nullable', 'string', 'max:32'],
            'seller_address' => ['nullable', 'string', 'max:255'],
            'vat_key' => ['nullable', 'string', 'max:16'],
            'block_id' => ['nullable', 'integer'],
            'receipt_block_id' => ['nullable', 'integer'],
            'bank_account_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Choosing Billingo with no numbering block at all would leave a tenant that
     * looks configured and fails on its first document — at which point the
     * customer has already paid.
     *
     * Either block satisfies this. A receipt is the default document (SLO-168),
     * so a receipt block alone is a complete setup; an invoice block alone still
     * serves the customers who ask for an invoice. Demanding both would refuse a
     * configuration that works.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('provider') !== InvoiceProvider::Billingo->value) {
                return;
            }

            $hasInvoice = $this->filled('block_id');
            $hasReceipt = $this->filled('receipt_block_id');

            if (! $hasInvoice && ! $hasReceipt) {
                $validator->errors()->add('receipt_block_id', __('app.invoicing.settings.block_required'));
            }
        });
    }
}
