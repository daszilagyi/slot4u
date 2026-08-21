<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * The optional billing block on a public booking form (SLO-168).
 *
 * A receipt is issued unless the buyer ticks "I need an invoice", and only then
 * are the address fields required — the Áfa tv. 169. § e) needs them on an
 * invoice and on nothing else. Asking everyone would collect personal data the
 * transaction does not require, which docs/19 asks us not to do.
 *
 * The fields are `nullable` in the rules and made required conditionally, so an
 * unticked form is not carrying six empty required fields.
 */
trait CollectsBillingDetails
{
    /**
     * @return array<string, mixed>
     */
    protected function billingRules(): array
    {
        return [
            'wants_invoice' => ['nullable', 'boolean'],
            'billing_name' => ['nullable', 'string', 'max:255'],
            'billing_tax_number' => ['nullable', 'string', 'max:32'],
            'billing_country_code' => ['nullable', 'string', 'size:2'],
            'billing_post_code' => ['nullable', 'string', 'max:16'],
            'billing_city' => ['nullable', 'string', 'max:128'],
            'billing_address' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Call from `withValidator()`. An invoice asked for with half an address is
     * refused at the form rather than accepted and downgraded to a receipt in
     * silence: the buyer asked for a document they would not have received.
     */
    protected function validateBillingDetails(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->boolean('wants_invoice')) {
                return;
            }

            foreach (['billing_name', 'billing_post_code', 'billing_city', 'billing_address'] as $field) {
                if (trim((string) $this->input($field)) === '') {
                    $validator->errors()->add($field, __('validation.required', [
                        'attribute' => __('app.invoicing.billing.'.$field),
                    ]));
                }
            }
        });
    }

    /**
     * The billing attributes to store on the booking.
     *
     * Everything is dropped when no invoice was asked for — a stale address left
     * behind by a ticked-then-unticked box would be personal data kept for
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function billingAttributes(): array
    {
        if (! $this->boolean('wants_invoice')) {
            return ['wants_invoice' => false];
        }

        return [
            'wants_invoice' => true,
            'billing_name' => $this->input('billing_name'),
            'billing_tax_number' => $this->input('billing_tax_number'),
            'billing_country_code' => $this->input('billing_country_code') ?: 'HU',
            'billing_post_code' => $this->input('billing_post_code'),
            'billing_city' => $this->input('billing_city'),
            'billing_address' => $this->input('billing_address'),
        ];
    }
}
