import { Head, useForm } from '@inertiajs/react';
import { LockIcon, SparklesIcon, UploadIcon } from 'lucide-react';
import { type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/components/admin/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';

type SettingsData = {
    description: string | null;
    email: string | null;
    phone: string | null;
    address_line: string | null;
    address_city: string | null;
    address_postal: string | null;
    opening_hours: string | null;
    social: Record<string, string>;
    cancellation_deadline_hours: number;
    slot_interval_minutes: number;
    refund_policy: string;
    refund_percent_bps: number;
};

type BrandingData = {
    primary_color: string;
    logo_url: string | null;
    cover_url: string | null;
};

type IndexProps = {
    name: string;
    settings: SettingsData;
    branding: BrandingData;
    brandingEnabled: boolean;
    slotIntervals: number[];
    socialPlatforms: string[];
    refundPolicies: string[];
    onlinePaymentEnabled: boolean;
};

export default function SettingsIndex({
    name,
    settings,
    branding,
    brandingEnabled,
    slotIntervals,
    socialPlatforms,
    refundPolicies,
    onlinePaymentEnabled,
}: IndexProps) {
    const t = useTranslations();

    const form = useForm({
        name,
        description: settings.description ?? '',
        email: settings.email ?? '',
        phone: settings.phone ?? '',
        address_line: settings.address_line ?? '',
        address_city: settings.address_city ?? '',
        address_postal: settings.address_postal ?? '',
        opening_hours: settings.opening_hours ?? '',
        social: socialPlatforms.reduce<Record<string, string>>((acc, key) => {
            acc[key] = settings.social[key] ?? '';
            return acc;
        }, {}),
        cancellation_deadline_hours: settings.cancellation_deadline_hours,
        slot_interval_minutes: settings.slot_interval_minutes,
        refund_policy: settings.refund_policy,
        // Entered as a percentage, stored in basis points (SLO-131).
        refund_percent: Math.round(settings.refund_percent_bps / 100),
        primary_color: branding.primary_color,
        logo: null as File | null,
        cover: null as File | null,
        remove_logo: false as boolean,
        remove_cover: false as boolean,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        // When branding is locked, never send its fields — the section is
        // read-only and the server rejects any branding input (SettingsRequest).
        form.transform((data) => {
            if (brandingEnabled) {
                return data;
            }
            return {
                name: data.name,
                description: data.description,
                email: data.email,
                phone: data.phone,
                address_line: data.address_line,
                address_city: data.address_city,
                address_postal: data.address_postal,
                opening_hours: data.opening_hours,
                social: data.social,
                cancellation_deadline_hours: data.cancellation_deadline_hours,
                slot_interval_minutes: data.slot_interval_minutes,
                refund_policy: data.refund_policy,
                refund_percent: data.refund_percent,
            };
        });
        form.post('/settings', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                toast.success(t('admin.settings.saved'));
                form.setData('logo', null);
                form.setData('cover', null);
                form.setData('remove_logo', false);
                form.setData('remove_cover', false);
            },
        });
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.settings.title') }]}>
            <Head title={t('admin.settings.title')} />

            <form onSubmit={submit} className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.settings.title')}
                    description={t('admin.settings.subtitle')}
                    actions={
                        <Button type="submit" disabled={form.processing}>
                            {t('admin.common.save')}
                        </Button>
                    }
                />

                {/* Company profile */}
                <section className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5">
                    <h2 className="text-lg font-semibold">
                        {t('admin.settings.profile_title')}
                    </h2>

                    <Field
                        id="set-name"
                        label={t('admin.settings.field.name')}
                        error={form.errors.name}
                    >
                        <Input
                            id="set-name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                        />
                    </Field>

                    <Field
                        id="set-desc"
                        label={t('admin.settings.field.description')}
                        error={form.errors.description}
                    >
                        <textarea
                            id="set-desc"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={3}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="set-email"
                            label={t('admin.settings.field.email')}
                            error={form.errors.email}
                        >
                            <Input
                                id="set-email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="set-phone"
                            label={t('admin.settings.field.phone')}
                            error={form.errors.phone}
                        >
                            <Input
                                id="set-phone"
                                type="tel"
                                inputMode="tel"
                                value={form.data.phone}
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field
                            id="set-addr"
                            label={t('admin.settings.field.address_line')}
                            error={form.errors.address_line}
                        >
                            <Input
                                id="set-addr"
                                value={form.data.address_line}
                                onChange={(e) =>
                                    form.setData('address_line', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="set-city"
                            label={t('admin.settings.field.address_city')}
                            error={form.errors.address_city}
                        >
                            <Input
                                id="set-city"
                                value={form.data.address_city}
                                onChange={(e) =>
                                    form.setData('address_city', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            id="set-postal"
                            label={t('admin.settings.field.address_postal')}
                            error={form.errors.address_postal}
                        >
                            <Input
                                id="set-postal"
                                value={form.data.address_postal}
                                onChange={(e) =>
                                    form.setData('address_postal', e.target.value)
                                }
                            />
                        </Field>
                    </div>

                    <Field
                        id="set-hours"
                        label={t('admin.settings.field.opening_hours')}
                        error={form.errors.opening_hours}
                    >
                        <textarea
                            id="set-hours"
                            value={form.data.opening_hours}
                            onChange={(e) =>
                                form.setData('opening_hours', e.target.value)
                            }
                            rows={2}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </Field>

                    <fieldset className="flex flex-col gap-3">
                        <legend className="text-sm font-medium">
                            {t('admin.settings.social_title')}
                        </legend>
                        {socialPlatforms.map((platform) => (
                            <Field
                                key={platform}
                                id={`set-social-${platform}`}
                                label={t(`admin.settings.social.${platform}`)}
                                error={form.errors[`social.${platform}` as 'social']}
                            >
                                <Input
                                    id={`set-social-${platform}`}
                                    type="url"
                                    value={form.data.social[platform] ?? ''}
                                    onChange={(e) =>
                                        form.setData('social', {
                                            ...form.data.social,
                                            [platform]: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                        ))}
                    </fieldset>
                </section>

                {/* Booking rules */}
                <section className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5">
                    <div>
                        <h2 className="text-lg font-semibold">
                            {t('admin.settings.rules_title')}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.settings.rules_subtitle')}
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="set-cancel"
                            label={t('admin.settings.field.cancellation_deadline')}
                            error={form.errors.cancellation_deadline_hours}
                        >
                            <Input
                                id="set-cancel"
                                type="number"
                                min={0}
                                max={336}
                                value={form.data.cancellation_deadline_hours}
                                onChange={(e) =>
                                    form.setData(
                                        'cancellation_deadline_hours',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            id="set-slot"
                            label={t('admin.settings.field.slot_interval')}
                            error={form.errors.slot_interval_minutes}
                        >
                            <select
                                id="set-slot"
                                value={form.data.slot_interval_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'slot_interval_minutes',
                                        Number(e.target.value),
                                    )
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                {slotIntervals.map((minutes) => (
                                    <option key={minutes} value={minutes}>
                                        {t('admin.settings.minutes', {
                                            count: minutes,
                                        })}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        {/* Refund policy (SLO-131) — only does anything for a
                            tenant that can take money online. */}
                        <Field
                            id="set-refund-policy"
                            label={t('admin.settings.field.refund_policy')}
                            error={form.errors.refund_policy}
                        >
                            <select
                                id="set-refund-policy"
                                value={form.data.refund_policy}
                                onChange={(e) =>
                                    form.setData('refund_policy', e.target.value)
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                {refundPolicies.map((policy) => (
                                    <option key={policy} value={policy}>
                                        {t(
                                            `admin.settings.refund_policy.${policy}`,
                                        )}
                                    </option>
                                ))}
                            </select>
                            <p className="text-xs text-muted-foreground">
                                {onlinePaymentEnabled
                                    ? t('admin.settings.refund_hint')
                                    : t('admin.settings.refund_hint_off')}
                            </p>
                        </Field>

                        {form.data.refund_policy === 'partial' ? (
                            <Field
                                id="set-refund-percent"
                                label={t('admin.settings.field.refund_percent')}
                                error={form.errors.refund_percent}
                            >
                                <Input
                                    id="set-refund-percent"
                                    type="number"
                                    min={1}
                                    max={100}
                                    value={form.data.refund_percent}
                                    onChange={(e) =>
                                        form.setData(
                                            'refund_percent',
                                            Number(e.target.value),
                                        )
                                    }
                                />
                            </Field>
                        ) : null}
                    </div>
                </section>

                {/* Branding — feature-gated */}
                <section className="relative flex flex-col gap-4 rounded-2xl border border-border bg-card p-5">
                    <div className="flex items-center gap-2">
                        <SparklesIcon className="size-5 text-primary" />
                        <h2 className="text-lg font-semibold">
                            {t('admin.settings.branding_title')}
                        </h2>
                    </div>

                    {brandingEnabled ? (
                        <>
                            <Field
                                id="set-color"
                                label={t('admin.settings.field.primary_color')}
                                error={form.errors.primary_color}
                            >
                                <input
                                    id="set-color"
                                    type="color"
                                    value={form.data.primary_color}
                                    onChange={(e) =>
                                        form.setData('primary_color', e.target.value)
                                    }
                                    className="size-9 cursor-pointer rounded-md border border-input bg-transparent"
                                />
                            </Field>

                            <ImageField
                                id="set-logo"
                                label={t('admin.settings.field.logo')}
                                currentUrl={branding.logo_url}
                                removed={form.data.remove_logo}
                                error={form.errors.logo}
                                onFile={(file) => {
                                    form.setData('logo', file);
                                    if (file) form.setData('remove_logo', false);
                                }}
                                onRemove={() => {
                                    form.setData('logo', null);
                                    form.setData('remove_logo', true);
                                }}
                                removeLabel={t('admin.settings.remove_image')}
                                uploadLabel={t('admin.settings.choose_image')}
                            />

                            <ImageField
                                id="set-cover"
                                label={t('admin.settings.field.cover')}
                                currentUrl={branding.cover_url}
                                removed={form.data.remove_cover}
                                error={form.errors.cover}
                                onFile={(file) => {
                                    form.setData('cover', file);
                                    if (file) form.setData('remove_cover', false);
                                }}
                                onRemove={() => {
                                    form.setData('cover', null);
                                    form.setData('remove_cover', true);
                                }}
                                removeLabel={t('admin.settings.remove_image')}
                                uploadLabel={t('admin.settings.choose_image')}
                            />
                        </>
                    ) : (
                        <div className="flex flex-col items-start gap-2 rounded-xl border border-dashed border-border bg-muted/30 p-6">
                            <div className="flex items-center gap-2 font-medium">
                                <LockIcon className="size-4" />
                                {t('admin.settings.branding_locked_title')}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.settings.branding_locked_body')}
                            </p>
                        </div>
                    )}
                </section>
            </form>
        </AdminLayout>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
    );
}

function ImageField({
    id,
    label,
    currentUrl,
    removed,
    error,
    onFile,
    onRemove,
    removeLabel,
    uploadLabel,
}: {
    id: string;
    label: string;
    currentUrl: string | null;
    removed: boolean;
    error?: string;
    onFile: (file: File | null) => void;
    onRemove: () => void;
    removeLabel: string;
    uploadLabel: string;
}) {
    const showCurrent = currentUrl !== null && !removed;

    return (
        <div className="flex flex-col gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="flex items-center gap-3">
                {showCurrent ? (
                    <img
                        src={currentUrl}
                        alt={label}
                        className="size-14 rounded-md border border-border object-cover"
                    />
                ) : null}
                <label
                    htmlFor={id}
                    className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-accent"
                >
                    <UploadIcon className="size-4" />
                    {uploadLabel}
                </label>
                <input
                    id={id}
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    className="sr-only"
                    onChange={(e) => onFile(e.target.files?.[0] ?? null)}
                />
                {showCurrent ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onRemove}
                    >
                        {removeLabel}
                    </Button>
                ) : null}
            </div>
            {error ? <p className="text-sm text-destructive">{error}</p> : null}
        </div>
    );
}
