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
            'bank_account_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * Choosing Billingo without a document block would leave a tenant that looks
     * configured and fails on its first real invoice — at which point the
     * customer has already paid.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('provider') !== InvoiceProvider::Billingo->value) {
                return;
            }

            if ($this->input('block_id') === null || $this->input('block_id') === '') {
                $validator->errors()->add('block_id', __('app.invoicing.settings.block_required'));
            }
        });
    }
}
