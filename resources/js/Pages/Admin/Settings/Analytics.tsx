import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type AnalyticsProps = {
    settings: {
        ga4_measurement_id: string | null;
        meta_pixel_id: string | null;
    };
    categories: {
        ga4: string;
        meta_pixel: string;
    };
};

/**
 * The tenant's own measurement ids (SLO-56).
 *
 * Both values are shown, unlike the invoicing API key: they are printed into
 * every public page anyway, so hiding them would protect nothing and would make
 * a typo impossible to notice. Clearing a field switches that vendor off.
 *
 * The screen says out loud which consent category each vendor waits for. A
 * settings page that reports "saved" and nothing else invites the conclusion
 * that measurement is now running — when in fact it runs for the share of
 * visitors who agreed, and for nobody else.
 */
export default function Analytics({ settings, categories }: AnalyticsProps) {
    const t = useTranslations();

    const form = useForm({
        ga4_measurement_id: settings.ga4_measurement_id ?? '',
        meta_pixel_id: settings.meta_pixel_id ?? '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post('/settings/analytics', { preserveScroll: true });
    }

    return (
        <AdminLayout>
            <Head title={t('analytics.settings.title')} />

            <div className="flex max-w-2xl flex-col gap-6">
                <PageHeader
                    title={t('analytics.settings.title')}
                    description={t('analytics.settings.description')}
                />

                <p className="rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm text-muted-foreground">
                    {t('analytics.settings.controller_notice')}
                </p>

                <form
                    onSubmit={submit}
                    className="flex flex-col gap-5 rounded-xl border border-border bg-card p-5"
                >
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="ga4_measurement_id">
                            {t('analytics.settings.ga4_label')}
                        </Label>
                        <Input
                            id="ga4_measurement_id"
                            inputMode="text"
                            autoComplete="off"
                            placeholder="G-XXXXXXXXXX"
                            value={form.data.ga4_measurement_id}
                            onChange={(event) =>
                                form.setData(
                                    'ga4_measurement_id',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('analytics.settings.ga4_hint', {
                                category: t(
                                    `consent.category.${categories.ga4}`,
                                ),
                            })}
                        </p>
                        {form.errors.ga4_measurement_id !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.ga4_measurement_id}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="meta_pixel_id">
                            {t('analytics.settings.pixel_label')}
                        </Label>
                        <Input
                            id="meta_pixel_id"
                            inputMode="numeric"
                            autoComplete="off"
                            placeholder="123456789012345"
                            value={form.data.meta_pixel_id}
                            onChange={(event) =>
                                form.setData(
                                    'meta_pixel_id',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {t('analytics.settings.pixel_hint', {
                                category: t(
                                    `consent.category.${categories.meta_pixel}`,
                                ),
                            })}
                        </p>
                        {form.errors.meta_pixel_id !== undefined && (
                            <p className="text-sm text-destructive">
                                {form.errors.meta_pixel_id}
                            </p>
                        )}
                    </div>

                    <p className="text-xs text-muted-foreground">
                        {t('analytics.settings.clear_hint')}
                    </p>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {t('common.save')}
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
