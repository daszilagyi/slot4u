/**
 * The billing fields a public booking form carries (SLO-168).
 *
 * All blank by default: a receipt is issued unless the buyer ticks "I need an
 * invoice", and a receipt needs none of them. Lives here rather than beside the
 * component so that file exports only components (fast refresh).
 */
export type BillingData = {
    wants_invoice: boolean;
    billing_name: string;
    billing_tax_number: string;
    billing_post_code: string;
    billing_city: string;
    billing_address: string;
};

export function useBillingFields(): BillingData {
    return {
        wants_invoice: false,
        billing_name: '',
        billing_tax_number: '',
        billing_post_code: '',
        billing_city: '',
        billing_address: '',
    };
}
