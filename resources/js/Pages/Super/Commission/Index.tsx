import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';

import AppLayout from '@/Layouts/AppLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime, formatMoney, formatRate } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';

type Version = {
    id: number;
    free_threshold_minor: number;
    rate_bps: number;
    rate_with_integration_bps: number;
    monthly_cap_minor: number | null;
    currency: string;
    effective_from: string;
    scheduled: boolean;
    creator: { id: number; name: string } | null;
    created_at: string | null;
};

type IndexProps = {
    versions: Version[];
    effective_id: number | null;
};

/**
 * Platform commission configuration (docs/10 §10). Versions are immutable: the
 * form publishes a new one rather than editing a row, which is what lets a past
 * period be reconstructed with the settings that actually priced it (§12/16).
 *
 * Amounts are in minor units and rates in basis points — the storage
 * representation, entered without conversion (docs/01 §6) and echoed back
 * formatted so the zero count stays checkable.
 */
export default function CommissionIndex({ versions, effective_id }: IndexProps) {
    const t = useTranslations();

    const form = useForm({
        free_threshold_minor: '',
        rate_bps: '',
        rate_with_integration_bps: '',
        monthly_cap_minor: '',
        currency: 'HUF',
        effective_from: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/commission', {
            preserveScroll: true,
            // reset() blanks the date field, so the backdate warning has to go
            // with it — it belongs to a value that no longer exists.
            onSuccess: () => {
                form.reset();
                setBackdated(false);
            },
        });
    }

    // A version dated in the past re-prices every period still open. Closed ones
    // never recompute (§8.2), so this warns rather than blocks. Evaluated on
    // change, not during render: reading the clock while rendering is impure
    // (react-hooks/purity).
    const [backdated, setBackdated] = useState(false);

    function setEffectiveFrom(value: string) {
        form.setData('effective_from', value);
        setBackdated(value !== '' && new Date(value).getTime() < Date.now());
    }

    return (
        <AppLayout>
            <Head title={t('super.commission.title')} />

            <div className="flex w-full max-w-4xl flex-col gap-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('super.commission.title')}
                        </h1>
                        <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
                            {t('super.commission.subtitle')}
                        </p>
                    </div>
                    <Link
                        href="/"
                        className="shrink-0 text-sm text-muted-foreground hover:text-foreground"
                    >
                        {t('super.commission.back')}
                    </Link>
                </div>

                {effective_id === null ? (
                    <p className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-500">
                        {t('super.commission.none_effective')}
                    </p>
                ) : null}

                {/* New version */}
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-1 text-lg font-medium">
                        {t('super.commission.form.title')}
                    </h2>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t('super.commission.form.subtitle')}
                    </p>

                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <NumberField
                            id="free_threshold_minor"
                            label={t('super.commission.form.threshold')}
                            hint={t('super.commission.form.threshold_hint')}
                            value={form.data.free_threshold_minor}
                            error={form.errors.free_threshold_minor}
                            preview={moneyPreview(
                                form.data.free_threshold_minor,
                                form.data.currency,
                            )}
                            onChange={(v) =>
                                form.setData('free_threshold_minor', v)
                            }
                        />
                        <NumberField
                            id="rate_bps"
                            label={t('super.commission.form.rate')}
                            hint={t('super.commission.form.rate_hint')}
                            value={form.data.rate_bps}
                            error={form.errors.rate_bps}
                            preview={ratePreview(form.data.rate_bps)}
                            onChange={(v) => form.setData('rate_bps', v)}
                        />
                        <NumberField
                            id="rate_with_integration_bps"
                            label={t('super.commission.form.rate_integration')}
                            hint={t(
                                'super.commission.form.rate_integration_hint',
                            )}
                            value={form.data.rate_with_integration_bps}
                            error={form.errors.rate_with_integration_bps}
                            preview={ratePreview(
                                form.data.rate_with_integration_bps,
                            )}
                            onChange={(v) =>
                                form.setData('rate_with_integration_bps', v)
                            }
                        />
                        <NumberField
                            id="monthly_cap_minor"
                            label={t('super.commission.form.cap')}
                            hint={t('super.commission.form.cap_hint')}
                            value={form.data.monthly_cap_minor}
                            error={form.errors.monthly_cap_minor}
                            preview={moneyPreview(
                                form.data.monthly_cap_minor,
                                form.data.currency,
                            )}
                            onChange={(v) => form.setData('monthly_cap_minor', v)}
                        />

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="effective_from">
                                {t('super.commission.form.effective_from')}
                            </Label>
                            <Input
                                id="effective_from"
                                type="datetime-local"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    setEffectiveFrom(e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('super.commission.form.effective_from_hint')}
                            </p>
                            {backdated ? (
                                <p className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-500">
                                    {t('super.commission.form.backdate_warning')}
                                </p>
                            ) : null}
                            {form.errors.effective_from ? (
                                <p className="text-sm text-red-500">
                                    {form.errors.effective_from}
                                </p>
                            ) : null}
                        </div>

                        <Button
                            type="submit"
                            className="self-start"
                            disabled={form.processing}
                        >
                            {t('super.commission.form.submit')}
                        </Button>
                    </form>
                </section>

                {/* History */}
                <section className="rounded-xl border border-border p-6">
                    <h2 className="mb-4 text-lg font-medium">
                        {t('super.commission.history_title')}
                    </h2>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="text-muted-foreground">
                                <tr className="border-b border-border">
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.effective_from')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.state')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.threshold')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.rate')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.rate_integration')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.cap')}
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        {t('super.commission.col.author')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {versions.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            {t('super.commission.empty')}
                                        </td>
                                    </tr>
                                ) : (
                                    versions.map((version) => (
                                        <tr
                                            key={version.id}
                                            className="border-b border-border/50 last:border-0"
                                        >
                                            <td className="px-3 py-2.5">
                                                {formatDateTime(
                                                    version.effective_from,
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <StateBadge
                                                    version={version}
                                                    effectiveId={effective_id}
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {formatMoney(
                                                    version.free_threshold_minor,
                                                    version.currency,
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {formatRate(version.rate_bps)}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {formatRate(
                                                    version.rate_with_integration_bps,
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {version.monthly_cap_minor ===
                                                null
                                                    ? t('super.commission.no_cap')
                                                    : formatMoney(
                                                          version.monthly_cap_minor,
                                                          version.currency,
                                                      )}
                                            </td>
                                            <td className="px-3 py-2.5 text-muted-foreground">
                                                {version.creator?.name ??
                                                    t(
                                                        'super.commission.system_author',
                                                    )}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}

function StateBadge({
    version,
    effectiveId,
}: {
    version: Version;
    effectiveId: number | null;
}) {
    const t = useTranslations();

    if (version.id === effectiveId) {
        return (
            <span className="rounded-full bg-green-500/15 px-2 py-0.5 text-xs font-medium text-green-400">
                {t('super.commission.effective_badge')}
            </span>
        );
    }

    if (version.scheduled) {
        return (
            <span className="rounded-full bg-blue-500/15 px-2 py-0.5 text-xs font-medium text-blue-400">
                {t('super.commission.scheduled_badge')}
            </span>
        );
    }

    return (
        <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
            {t('super.commission.superseded_badge')}
        </span>
    );
}

/** Echo a minor-unit input back as money; blank until it parses as an integer. */
function moneyPreview(value: string, currency: string): string | null {
    return /^\d+$/.test(value) ? formatMoney(Number(value), currency) : null;
}

function ratePreview(value: string): string | null {
    return /^\d+$/.test(value) ? formatRate(Number(value)) : null;
}

function NumberField({
    id,
    label,
    hint,
    value,
    error,
    preview,
    onChange,
}: {
    id: string;
    label: string;
    hint: string;
    value: string;
    error?: string;
    preview: string | null;
    onChange: (value: string) => void;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-3">
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
                {preview === null ? null : (
                    <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                        = {preview}
                    </span>
                )}
            </div>
            <p className="text-xs text-muted-foreground">{hint}</p>
            {error ? <p className="text-sm text-red-500">{error}</p> : null}
        </div>
    );
}
