import { router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatMoney, formatRate } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

export type CommissionOverride = {
    free_threshold_minor: number | null;
    rate_bps: number | null;
    rate_with_integration_bps: number | null;
    monthly_cap_minor: number | null;
    note: string | null;
    updated_at: string | null;
};

/** What the tenant is priced at once platform version and override are merged. */
export type CommissionEffective = {
    free_threshold_minor: number;
    rate_bps: number;
    rate_with_integration_bps: number;
    monthly_cap_minor: number | null;
    currency: string;
};

type Props = {
    tenantId: number;
    override: CommissionOverride | null;
    effective: CommissionEffective | null;
};

/**
 * Per-tenant commission override editor (docs/10 §5.2, §10). An empty field
 * inherits the platform version, so the inherited value is shown under each one
 * — otherwise "empty" reads as "zero", and a zero threshold bills from the first
 * forint.
 *
 * Amounts are entered in minor units and rates in basis points, matching how
 * they are stored: no conversion, no float, no rounding surprise. The formatted
 * value under each field keeps that readable.
 */
export default function CommissionOverrideSection({
    tenantId,
    override,
    effective,
}: Props) {
    const t = useTranslations();

    const form = useForm({
        free_threshold_minor: override?.free_threshold_minor ?? '',
        rate_bps: override?.rate_bps ?? '',
        rate_with_integration_bps: override?.rate_with_integration_bps ?? '',
        monthly_cap_minor: override?.monthly_cap_minor ?? '',
        note: override?.note ?? '',
    });

    const currency = effective?.currency ?? 'HUF';

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(`/tenants/${tenantId}/commission-override`, {
            preserveScroll: true,
        });
    }

    function clear() {
        if (window.confirm(t('super.commission.override.clear_confirm'))) {
            router.delete(`/tenants/${tenantId}/commission-override`, {
                preserveScroll: true,
            });
        }
    }

    return (
        <section className="rounded-xl border border-border p-6">
            <h2 className="mb-1 text-lg font-medium">
                {t('super.commission.override.title')}
            </h2>
            <p className="mb-4 text-sm text-muted-foreground">
                {t('super.commission.override.subtitle')}
            </p>

            <p className="mb-4 text-sm">
                {override
                    ? t('super.commission.override.active')
                    : t('super.commission.override.none')}
                {override?.updated_at ? (
                    <span className="ml-2 text-muted-foreground">
                        {t('super.commission.override.updated_at', {
                            time: formatDateTime(override.updated_at),
                        })}
                    </span>
                ) : null}
            </p>

            {effective === null ? (
                <p className="mb-4 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-500">
                    {t('super.commission.override.not_configured')}
                </p>
            ) : null}

            <form onSubmit={submit} className="flex flex-col gap-4">
                <Field
                    id="override_threshold"
                    label={t('super.commission.form.threshold')}
                    value={form.data.free_threshold_minor}
                    error={form.errors.free_threshold_minor}
                    inherited={
                        effective === null
                            ? null
                            : formatMoney(effective.free_threshold_minor, currency)
                    }
                    onChange={(v) => form.setData('free_threshold_minor', v)}
                />
                <Field
                    id="override_rate"
                    label={t('super.commission.form.rate')}
                    value={form.data.rate_bps}
                    error={form.errors.rate_bps}
                    inherited={
                        effective === null ? null : formatRate(effective.rate_bps)
                    }
                    onChange={(v) => form.setData('rate_bps', v)}
                />
                <Field
                    id="override_rate_integration"
                    label={t('super.commission.form.rate_integration')}
                    value={form.data.rate_with_integration_bps}
                    error={form.errors.rate_with_integration_bps}
                    inherited={
                        effective === null
                            ? null
                            : formatRate(effective.rate_with_integration_bps)
                    }
                    onChange={(v) =>
                        form.setData('rate_with_integration_bps', v)
                    }
                />
                <Field
                    id="override_cap"
                    label={t('super.commission.form.cap')}
                    value={form.data.monthly_cap_minor}
                    error={form.errors.monthly_cap_minor}
                    inherited={
                        effective === null
                            ? null
                            : effective.monthly_cap_minor === null
                              ? t('super.commission.no_cap')
                              : formatMoney(effective.monthly_cap_minor, currency)
                    }
                    onChange={(v) => form.setData('monthly_cap_minor', v)}
                />

                <div className="flex flex-col gap-2">
                    <Label htmlFor="override_note">
                        {t('super.commission.override.note')}
                    </Label>
                    <Input
                        id="override_note"
                        value={form.data.note}
                        placeholder={t(
                            'super.commission.override.note_placeholder',
                        )}
                        onChange={(e) => form.setData('note', e.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        {t('super.commission.override.note_hint')}
                    </p>
                    {form.errors.note ? (
                        <p className="text-sm text-red-500">
                            {form.errors.note}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {t('super.commission.override.save')}
                    </Button>
                    {override ? (
                        <Button type="button" variant="outline" onClick={clear}>
                            {t('super.commission.override.clear')}
                        </Button>
                    ) : null}
                </div>
            </form>
        </section>
    );
}

function Field({
    id,
    label,
    value,
    error,
    inherited,
    onChange,
}: {
    id: string;
    label: string;
    value: number | string;
    error?: string;
    inherited: string | null;
    onChange: (value: string) => void;
}) {
    const t = useTranslations();

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                inputMode="numeric"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
            {inherited === null ? null : (
                <p className="text-xs text-muted-foreground">
                    {t('super.commission.override.inherited', {
                        value: inherited,
                    })}
                </p>
            )}
            {error ? <p className="text-sm text-red-500">{error}</p> : null}
        </div>
    );
}
