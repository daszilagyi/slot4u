import { Head, router, useForm } from '@inertiajs/react';
import { UploadIcon } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import AppLayout from '@/Layouts/AppLayout';
import MailSettingsNav from '@/components/super/MailSettingsNav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postFormJson } from '@/lib/http';
import { useTranslations } from '@/lib/i18n';

type ColourKey = 'header_background' | 'button_background' | 'canvas';

type EditProps = {
    brand: Record<ColourKey, string> & {
        footer_text: string | null;
        has_logo: boolean;
    };
    defaults: Record<ColourKey, string>;
    customised: boolean;
    mail_count: number;
};

type Sample = 'platform' | 'tenant';

type Preview = {
    html: string;
    footer_contrast: number;
    footer_contrast_ok: boolean;
};

const HEX = /^#[0-9a-fA-F]{6}$/;

/**
 * The look every system email shares (SLO-245, docs/27).
 *
 * The preview is the server's real mail frame, not a React imitation of it: a
 * lookalike would drift from what customers receive the first time either side
 * changed. Each edit posts the draft and swaps the iframe's document.
 *
 * ⚠️ The iframe is sandboxed with nothing allowed. The document is our own
 * render, but it contains links, and a click must not navigate the panel.
 */
export default function SuperMailBrandEdit({
    brand,
    defaults,
    customised,
    mail_count,
}: EditProps) {
    const t = useTranslations();

    const form = useForm<{
        header_background: string;
        button_background: string;
        canvas: string;
        footer_text: string;
        logo: File | null;
        remove_logo: boolean;
    }>({
        header_background: brand.header_background,
        button_background: brand.button_background,
        canvas: brand.canvas,
        footer_text: brand.footer_text ?? '',
        logo: null,
        remove_logo: false,
    });

    const [sample, setSample] = useState<Sample>('platform');
    const [preview, setPreview] = useState<Preview | null>(null);
    const [previewState, setPreviewState] = useState<'loading' | 'ready' | 'failed'>('loading');
    const request = useRef(0);

    const { header_background, button_background, canvas, footer_text, logo, remove_logo } =
        form.data;

    useEffect(() => {
        // A half-typed hex is not a colour yet: keep the last good preview.
        if (![header_background, button_background, canvas].every((c) => HEX.test(c))) {
            return;
        }

        const id = ++request.current;
        const timer = window.setTimeout(async () => {
            setPreviewState('loading');

            const body = new FormData();
            body.append('header_background', header_background);
            body.append('button_background', button_background);
            body.append('canvas', canvas);
            body.append('footer_text', footer_text);
            body.append('remove_logo', remove_logo ? '1' : '0');
            body.append('sample', sample);
            if (logo) body.append('logo', logo);

            try {
                const data = await postFormJson<Preview>('/emails/design/preview', body);

                // Only the newest request may paint: an earlier, slower answer
                // would otherwise overwrite the draft the admin is looking at.
                if (id === request.current) {
                    setPreview(data);
                    setPreviewState('ready');
                }
            } catch {
                if (id === request.current) setPreviewState('failed');
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [header_background, button_background, canvas, footer_text, logo, remove_logo, sample]);

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post('/emails/design', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                toast.success(t('super.mail_brand.saved'));
                form.setData((data) => ({ ...data, logo: null, remove_logo: false }));
            },
        });
    }

    function reset() {
        if (!window.confirm(t('super.mail_brand.reset_confirm'))) return;

        router.delete('/emails/design', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('super.mail_brand.reset_done'));
                form.setData({
                    ...defaults,
                    footer_text: '',
                    logo: null,
                    remove_logo: false,
                });
            },
        });
    }

    const colourFields: ColourKey[] = ['header_background', 'button_background', 'canvas'];
    const contrastLow = preview !== null && !preview.footer_contrast_ok;

    let logoState = t('super.mail_brand.logo_default');
    if (logo) logoState = t('super.mail_brand.logo_pending', { name: logo.name });
    else if (brand.has_logo && !remove_logo) logoState = t('super.mail_brand.logo_custom');

    return (
        <AppLayout>
            <Head title={t('super.mail_brand.title')} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-8 sm:px-6">
                <MailSettingsNav current="design" mailCount={mail_count} />

                <header className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {t('super.mail_brand.title')}
                        </h1>
                        <Badge variant={customised ? 'default' : 'secondary'}>
                            {customised
                                ? t('super.mail_brand.customised')
                                : t('super.mail_brand.default')}
                        </Badge>
                    </div>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        {t('super.mail_brand.subtitle')}
                    </p>
                </header>

                <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    <form onSubmit={submit} className="flex flex-col gap-5">
                        {colourFields.map((key) => (
                            <div key={key} className="flex flex-col gap-2">
                                <Label htmlFor={`mb-${key}`}>
                                    {t(`super.mail_brand.fields.${key}`)}
                                </Label>
                                <div className="flex items-center gap-2">
                                    <input
                                        type="color"
                                        aria-label={t(`super.mail_brand.fields.${key}`)}
                                        value={HEX.test(form.data[key]) ? form.data[key] : defaults[key]}
                                        onChange={(e) =>
                                            form.setData(key, e.target.value.toUpperCase())
                                        }
                                        className="size-9 shrink-0 cursor-pointer rounded-md border border-input bg-transparent"
                                    />
                                    <Input
                                        id={`mb-${key}`}
                                        value={form.data[key]}
                                        maxLength={7}
                                        onChange={(e) => form.setData(key, e.target.value)}
                                        className="font-mono uppercase"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        disabled={form.data[key] === defaults[key]}
                                        onClick={() => form.setData(key, defaults[key])}
                                    >
                                        {t('super.mail_brand.use_default')}
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {t(`super.mail_brand.hints.${key}`)}
                                </p>
                                {form.errors[key] ? (
                                    <p className="text-sm text-destructive">{form.errors[key]}</p>
                                ) : null}
                                {key === 'canvas' && preview !== null ? (
                                    <p
                                        className={`text-xs ${contrastLow ? 'text-destructive' : 'text-muted-foreground'}`}
                                    >
                                        {t(
                                            contrastLow
                                                ? 'super.mail_brand.contrast_low'
                                                : 'super.mail_brand.contrast_ok',
                                            {
                                                ratio: preview.footer_contrast.toLocaleString('hu-HU', {
                                                    maximumFractionDigits: 1,
                                                }),
                                            },
                                        )}
                                    </p>
                                ) : null}
                            </div>
                        ))}

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="mb-footer">{t('super.mail_brand.fields.footer_text')}</Label>
                            <textarea
                                id="mb-footer"
                                rows={3}
                                maxLength={300}
                                value={footer_text}
                                onChange={(e) => form.setData('footer_text', e.target.value)}
                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <p className="text-xs text-muted-foreground">
                                {t('super.mail_brand.hints.footer_text')}
                            </p>
                            {form.errors.footer_text ? (
                                <p className="text-sm text-destructive">{form.errors.footer_text}</p>
                            ) : null}
                        </div>

                        <div className="flex flex-col gap-2">
                            <Label htmlFor="mb-logo">{t('super.mail_brand.fields.logo')}</Label>
                            <p className="text-sm">{logoState}</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <label
                                    htmlFor="mb-logo"
                                    className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-accent"
                                >
                                    <UploadIcon className="size-4" />
                                    {t('super.mail_brand.logo_choose')}
                                </label>
                                <input
                                    id="mb-logo"
                                    type="file"
                                    accept="image/png,image/jpeg"
                                    className="sr-only"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        form.setData((data) => ({
                                            ...data,
                                            logo: file,
                                            remove_logo: file ? false : data.remove_logo,
                                        }));
                                    }}
                                />
                                {logo || (brand.has_logo && !remove_logo) ? (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            form.setData((data) => ({
                                                ...data,
                                                logo: null,
                                                remove_logo: brand.has_logo,
                                            }))
                                        }
                                    >
                                        {t('super.mail_brand.logo_remove')}
                                    </Button>
                                ) : null}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t('super.mail_brand.hints.logo')}
                            </p>
                            {form.errors.logo ? (
                                <p className="text-sm text-destructive">{form.errors.logo}</p>
                            ) : null}
                        </div>

                        <div className="flex flex-wrap gap-2 pt-2">
                            <Button type="submit" disabled={form.processing || contrastLow}>
                                {t('super.mail_brand.save')}
                            </Button>
                            {customised ? (
                                <Button type="button" variant="outline" onClick={reset}>
                                    {t('super.mail_brand.reset')}
                                </Button>
                            ) : null}
                        </div>
                    </form>

                    <section className="flex min-w-0 flex-col gap-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-base font-semibold">
                                {t('super.mail_brand.preview.title')}
                            </h2>
                            <div className="inline-flex rounded-md border border-input p-0.5" role="tablist">
                                {(['platform', 'tenant'] as const).map((value) => (
                                    <button
                                        key={value}
                                        type="button"
                                        role="tab"
                                        aria-selected={sample === value}
                                        onClick={() => setSample(value)}
                                        className={`rounded px-3 py-1 text-sm ${
                                            sample === value
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {t(`super.mail_brand.preview.tab_${value}`)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <p aria-live="polite" className="min-h-5 text-xs text-muted-foreground">
                            {previewState === 'loading' ? t('super.mail_brand.preview.loading') : null}
                            {previewState === 'failed' ? t('super.mail_brand.preview.failed') : null}
                        </p>

                        <div className="overflow-hidden rounded-xl border border-border bg-white">
                            <iframe
                                title={t('super.mail_brand.preview.frame_title')}
                                sandbox=""
                                srcDoc={preview?.html ?? ''}
                                className="block h-[880px] w-full"
                            />
                        </div>
                    </section>
                </div>
            </div>
        </AppLayout>
    );
}
