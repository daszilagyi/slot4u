import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BillingData } from '@/lib/billing';
import { useTranslations } from '@/lib/i18n';

/**
 * The optional billing block on a public booking form (SLO-168).
 *
 * Hidden behind a tick box, and that is the design rather than an economy of
 * space: a receipt is issued by default, and a receipt needs none of this. The
 * Áfa tv. 169. § e) requires a buyer's name and address on an INVOICE — so
 * asking everyone would collect personal data most bookings never need, which is
 * the opposite of what docs/19 asks of us.
 *
 * Renders nothing when the tenant does not invoice at all.
 */

type Props = {
    data: BillingData;
    errors: Partial<Record<keyof BillingData, string>>;
    /**
     * Loosely typed on purpose: Inertia's `setData` is generic over the whole
     * form shape, and a per-key signature here cannot line up with it without
     * threading that shape through every caller.
     */
    onChange: (key: keyof BillingData, value: string | boolean) => void;
    /** The tenant's invoicing integration; without it there is nothing to ask. */
    enabled: boolean;
    /** Distinguishes the ids when several forms share a page. */
    prefix: string;
};

export function BillingFields({
    data,
    errors,
    onChange,
    enabled,
    prefix,
}: Props) {
    const t = useTranslations();

    if (!enabled) {
        return null;
    }

    const text = (
        key: 'billing_name' | 'billing_tax_number' | 'billing_post_code' | 'billing_city' | 'billing_address',
        className?: string,
    ) => (
        <div className={`flex flex-col gap-1.5 ${className ?? ''}`}>
            <Label htmlFor={`${prefix}-${key}`}>
                {t(`invoicing.billing.${key}`)}
            </Label>
            <Input
                id={`${prefix}-${key}`}
                value={data[key]}
                onChange={(event) => onChange(key, event.target.value)}
            />
            {errors[key] !== undefined && (
                <p className="text-sm text-destructive">{errors[key]}</p>
            )}
        </div>
    );

    return (
        <div className="flex flex-col gap-3 rounded-lg border border-border p-3">
            <label className="flex items-start gap-2.5 text-sm">
                <input
                    type="checkbox"
                    checked={data.wants_invoice}
                    onChange={(event) =>
                        onChange('wants_invoice', event.target.checked)
                    }
                    className="mt-0.5 size-4 shrink-0 rounded border-input"
                />
                <span>
                    <span className="block font-medium">
                        {t('invoicing.billing.wants_invoice')}
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        {t('invoicing.billing.hint')}
                    </span>
                </span>
            </label>

            {data.wants_invoice && (
                <div className="grid gap-4 sm:grid-cols-2">
                    {text('billing_name', 'sm:col-span-2')}
                    {text('billing_post_code')}
                    {text('billing_city')}
                    {text('billing_address', 'sm:col-span-2')}
                    {text('billing_tax_number', 'sm:col-span-2')}
                </div>
            )}
        </div>
    );
}
