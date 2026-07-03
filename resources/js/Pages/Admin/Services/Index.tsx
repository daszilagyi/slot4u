import { Head, router, useForm } from '@inertiajs/react';
import {
    FolderPlusIcon,
    PencilIcon,
    PlusIcon,
    SparklesIcon,
    Trash2Icon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import ConfirmDialog from '@/components/admin/ConfirmDialog';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFeatures } from '@/lib/features';
import { useTranslations } from '@/lib/i18n';
import type {
    AssignableRoom,
    AssignableStaff,
    BookingModeValue,
    FulfillmentTypeValue,
    Service,
    ServiceCategory,
} from '@/types';

/** Single-currency MVP (docs/10): every service is priced in the tenant currency. */
const DEFAULT_CURRENCY = 'HUF';

type IndexProps = {
    services: Service[];
    categories: ServiceCategory[];
    staff: AssignableStaff[];
    rooms: AssignableRoom[];
    bookingModes: BookingModeValue[];
};

/** Which form sections a booking mode exposes (mirrors the BookingMode enum). */
const MODE_FIELDS: Record<
    BookingModeValue,
    {
        duration?: boolean;
        buffers?: boolean;
        capacity?: boolean;
        waitlist?: boolean;
        room?: boolean;
        rental?: boolean;
        fulfillment?: boolean;
        quote?: boolean;
    }
> = {
    no_time_slot: { fulfillment: true },
    duration_based: { duration: true, buffers: true },
    event_based: { capacity: true, waitlist: true },
    resource_rental: { duration: true, buffers: true, room: true, rental: true },
    quote_request: { quote: true },
};

const FULFILLMENT_TYPES = ['digital', 'manual', 'downloadable'] as const;

const emptyForm = {
    name: '',
    description: '',
    category_id: '' as number | '',
    booking_mode: 'duration_based' as BookingModeValue,
    duration_minutes: 60 as number | '',
    buffer_before_minutes: 0 as number | '',
    buffer_after_minutes: 0 as number | '',
    price: 0 as number | '',
    capacity: '' as number | '',
    requires_staff: true,
    requires_room: false,
    requires_approval: false,
    waitlist_enabled: false,
    online_payment_required: false,
    active: true,
    fulfillment_type: 'digital' as FulfillmentTypeValue,
    min_duration_minutes: '' as number | '',
    max_duration_minutes: '' as number | '',
    deposit: '' as number | '',
    quote_fields: [] as string[],
    staff_ids: [] as number[],
    room_ids: [] as number[],
};

type ServiceForm = typeof emptyForm;

function num(value: number | ''): number | null {
    return value === '' ? null : Number(value);
}

/** Format integer minor units as a localized currency string (no hardcoded symbol). */
function formatPrice(minor: number, currency: string): string {
    return new Intl.NumberFormat('hu-HU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(minor / 100);
}

export default function ServicesIndex({
    services,
    categories,
    staff,
    rooms,
    bookingModes,
}: IndexProps) {
    const t = useTranslations();
    const hasFeature = useFeatures();

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<Service | null>(null);
    const [deleting, setDeleting] = useState<Service | null>(null);

    const [categorySheetOpen, setCategorySheetOpen] = useState(false);
    const [editingCategory, setEditingCategory] =
        useState<ServiceCategory | null>(null);
    const [deletingCategory, setDeletingCategory] =
        useState<ServiceCategory | null>(null);

    const form = useForm<ServiceForm>({ ...emptyForm });
    const categoryForm = useForm({ name: '' });

    const fields = MODE_FIELDS[form.data.booking_mode];

    // quote_request depends on feature_quote_request (mirrors the backend gate in
    // ServiceRequest). Hide it when off, but keep it selectable while editing a
    // service that already uses it so the form is never left in an invalid mode.
    const availableModes = bookingModes.filter(
        (mode) =>
            mode !== 'quote_request' ||
            hasFeature('feature_quote_request') ||
            editing?.booking_mode === 'quote_request',
    );

    const grouped = useMemo(() => {
        const byCategory = new Map<number | null, Service[]>();
        for (const service of services) {
            const key = service.category_id;
            byCategory.set(key, [...(byCategory.get(key) ?? []), service]);
        }
        return byCategory;
    }, [services]);

    function openCreate() {
        setEditing(null);
        form.clearErrors();
        form.setDefaults({ ...emptyForm });
        form.reset();
        setSheetOpen(true);
    }

    function openEdit(service: Service) {
        setEditing(service);
        form.clearErrors();
        form.setData({
            name: service.name,
            description: service.description ?? '',
            category_id: service.category_id ?? '',
            booking_mode: service.booking_mode,
            duration_minutes: service.duration_minutes ?? '',
            buffer_before_minutes: service.buffer_before_minutes,
            buffer_after_minutes: service.buffer_after_minutes,
            price: service.price_minor / 100,
            capacity: service.capacity ?? '',
            requires_staff: service.requires_staff,
            requires_room: service.requires_room,
            requires_approval: service.requires_approval,
            waitlist_enabled: service.waitlist_enabled,
            online_payment_required: service.online_payment_required,
            active: service.active,
            fulfillment_type: service.settings?.fulfillment_type ?? 'digital',
            min_duration_minutes: service.settings?.min_duration_minutes ?? '',
            max_duration_minutes: service.settings?.max_duration_minutes ?? '',
            deposit:
                service.settings?.deposit_minor != null
                    ? service.settings.deposit_minor / 100
                    : '',
            quote_fields: service.settings?.quote_fields ?? [],
            staff_ids: service.staff_ids,
            room_ids: service.room_ids,
        });
        setSheetOpen(true);
    }

    function buildSettings(data: ServiceForm) {
        const f = MODE_FIELDS[data.booking_mode];
        if (f.fulfillment) {
            return { fulfillment_type: data.fulfillment_type };
        }
        if (f.rental) {
            return {
                min_duration_minutes: num(data.min_duration_minutes),
                max_duration_minutes: num(data.max_duration_minutes),
                deposit_minor:
                    data.deposit === '' ? null : Math.round(Number(data.deposit) * 100),
            };
        }
        if (f.quote) {
            return {
                quote_fields: data.quote_fields.filter((q) => q.trim() !== ''),
            };
        }
        return null;
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        form.transform((data) => ({
            name: data.name,
            description: data.description || null,
            category_id: data.category_id === '' ? null : data.category_id,
            booking_mode: data.booking_mode,
            duration_minutes: num(data.duration_minutes),
            buffer_before_minutes: num(data.buffer_before_minutes) ?? 0,
            buffer_after_minutes: num(data.buffer_after_minutes) ?? 0,
            capacity: num(data.capacity),
            price_minor: Math.round(Number(data.price || 0) * 100),
            currency: DEFAULT_CURRENCY,
            requires_staff: data.requires_staff,
            requires_room: data.requires_room,
            requires_approval: data.requires_approval,
            waitlist_enabled: data.waitlist_enabled,
            online_payment_required: data.online_payment_required,
            active: data.active,
            settings: buildSettings(data),
            staff_ids: data.staff_ids,
            room_ids: data.room_ids,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editing
                        ? t('admin.services.updated')
                        : t('admin.services.created'),
                );
                setSheetOpen(false);
            },
        };

        if (editing) {
            form.put(`/services/${editing.id}`, options);
        } else {
            form.post('/services', options);
        }
    }

    function confirmDelete() {
        if (!deleting) return;
        router.delete(`/services/${deleting.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.services.deleted')),
            onError: (errors) =>
                toast.error(errors.delete ?? t('admin.services.error.has_bookings')),
        });
    }

    // --- categories ---
    function openCreateCategory() {
        setEditingCategory(null);
        categoryForm.clearErrors();
        categoryForm.setData({ name: '' });
        setCategorySheetOpen(true);
    }

    function openEditCategory(category: ServiceCategory) {
        setEditingCategory(category);
        categoryForm.clearErrors();
        categoryForm.setData({ name: category.name });
        setCategorySheetOpen(true);
    }

    function submitCategory(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editingCategory
                        ? t('admin.services.category.updated')
                        : t('admin.services.category.created'),
                );
                setCategorySheetOpen(false);
            },
        };
        if (editingCategory) {
            categoryForm.put(`/service-categories/${editingCategory.id}`, options);
        } else {
            categoryForm.post('/service-categories', options);
        }
    }

    function confirmDeleteCategory() {
        if (!deletingCategory) return;
        router.delete(`/service-categories/${deletingCategory.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.services.category.deleted')),
        });
    }

    function toggleId(list: number[], id: number): number[] {
        return list.includes(id)
            ? list.filter((x) => x !== id)
            : [...list, id];
    }

    const sections: { category: ServiceCategory | null; items: Service[] }[] = [
        ...categories.map((category) => ({
            category,
            items: grouped.get(category.id) ?? [],
        })),
        { category: null, items: grouped.get(null) ?? [] },
    ];

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.services.title') }]}>
            <Head title={t('admin.services.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.services.title')}
                    description={t('admin.services.subtitle')}
                    actions={
                        <div className="flex items-center gap-2">
                            <Button variant="outline" onClick={openCreateCategory}>
                                <FolderPlusIcon className="size-4" />
                                {t('admin.services.category.add')}
                            </Button>
                            <Button onClick={openCreate}>
                                <PlusIcon className="size-4" />
                                {t('admin.services.add')}
                            </Button>
                        </div>
                    }
                />

                {services.length === 0 && categories.length === 0 ? (
                    <EmptyState
                        icon={SparklesIcon}
                        title={t('admin.services.empty_title')}
                        description={t('admin.services.empty_body')}
                        action={
                            <Button onClick={openCreate}>
                                <PlusIcon className="size-4" />
                                {t('admin.services.add')}
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-4">
                        {sections.map(({ category, items }) => {
                            if (category === null && items.length === 0) {
                                return null;
                            }
                            return (
                                <section
                                    key={category?.id ?? 'uncategorized'}
                                    className="rounded-2xl border border-border bg-card p-5"
                                >
                                    <div className="mb-3 flex items-center justify-between gap-2">
                                        <h2 className="text-lg font-semibold">
                                            {category
                                                ? category.name
                                                : t('admin.services.uncategorized')}
                                        </h2>
                                        {category ? (
                                            <div className="flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        openEditCategory(category)
                                                    }
                                                    aria-label={t('admin.common.edit')}
                                                >
                                                    <PencilIcon className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        setDeletingCategory(category)
                                                    }
                                                    aria-label={t('admin.common.delete')}
                                                >
                                                    <Trash2Icon className="size-4 text-destructive" />
                                                </Button>
                                            </div>
                                        ) : null}
                                    </div>

                                    {items.length === 0 ? (
                                        <p className="text-sm text-muted-foreground">
                                            {t('admin.services.category.empty')}
                                        </p>
                                    ) : (
                                        <ul className="flex flex-col divide-y divide-border">
                                            {items.map((service) => (
                                                <li
                                                    key={service.id}
                                                    className="flex flex-wrap items-center justify-between gap-2 py-2.5"
                                                >
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">
                                                            {service.name}
                                                        </span>
                                                        <Badge variant="outline">
                                                            {t(
                                                                `admin.services.mode.${service.booking_mode}`,
                                                            )}
                                                        </Badge>
                                                        {service.duration_minutes ? (
                                                            <span className="text-sm text-muted-foreground">
                                                                {t(
                                                                    'admin.services.minutes',
                                                                    {
                                                                        count: service.duration_minutes,
                                                                    },
                                                                )}
                                                            </span>
                                                        ) : null}
                                                        <span className="text-sm font-medium text-muted-foreground">
                                                            {formatPrice(
                                                                service.price_minor,
                                                                service.currency,
                                                            )}
                                                        </span>
                                                        {service.requires_approval ? (
                                                            <Badge variant="secondary">
                                                                {t(
                                                                    'admin.services.badge.approval',
                                                                )}
                                                            </Badge>
                                                        ) : null}
                                                        {service.active ? null : (
                                                            <Badge variant="secondary">
                                                                {t(
                                                                    'admin.services.inactive',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div className="flex gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                openEdit(service)
                                                            }
                                                            aria-label={t(
                                                                'admin.common.edit',
                                                            )}
                                                        >
                                                            <PencilIcon className="size-4" />
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            onClick={() =>
                                                                setDeleting(service)
                                                            }
                                                            aria-label={t(
                                                                'admin.common.delete',
                                                            )}
                                                        >
                                                            <Trash2Icon className="size-4 text-destructive" />
                                                        </Button>
                                                    </div>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </section>
                            );
                        })}
                    </div>
                )}
            </div>

            {/* Service create/edit */}
            <FormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    editing
                        ? t('admin.services.edit_title')
                        : t('admin.services.new_title')
                }
                description={t('admin.services.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submit}
                submitting={form.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="svc-name">{t('admin.services.field.name')}</Label>
                    <Input
                        id="svc-name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        autoFocus
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-destructive">{form.errors.name}</p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="svc-mode">
                        {t('admin.services.field.mode')}
                    </Label>
                    <select
                        id="svc-mode"
                        value={form.data.booking_mode}
                        onChange={(e) =>
                            form.setData(
                                'booking_mode',
                                e.target.value as BookingModeValue,
                            )
                        }
                        aria-describedby="svc-mode-hint"
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {availableModes.map((mode) => (
                            <option key={mode} value={mode}>
                                {t(`admin.services.mode.${mode}`)}
                            </option>
                        ))}
                    </select>
                    <p
                        id="svc-mode-hint"
                        className="text-xs text-muted-foreground"
                    >
                        {t(`admin.services.mode_hint.${form.data.booking_mode}`)}
                    </p>
                    {form.errors.booking_mode ? (
                        <p className="text-sm text-destructive">
                            {form.errors.booking_mode}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="svc-category">
                        {t('admin.services.field.category')}
                    </Label>
                    <select
                        id="svc-category"
                        value={form.data.category_id}
                        onChange={(e) =>
                            form.setData(
                                'category_id',
                                e.target.value === '' ? '' : Number(e.target.value),
                            )
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="">
                            {t('admin.services.uncategorized')}
                        </option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="svc-desc">
                        {t('admin.services.field.description')}
                    </Label>
                    <textarea
                        id="svc-desc"
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        rows={2}
                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                </div>

                {fields.duration ? (
                    <div className="flex gap-3">
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-duration">
                                {t('admin.services.field.duration')}
                            </Label>
                            <Input
                                id="svc-duration"
                                type="number"
                                min={1}
                                value={form.data.duration_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'duration_minutes',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                            {form.errors.duration_minutes ? (
                                <p className="text-sm text-destructive">
                                    {form.errors.duration_minutes}
                                </p>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                {fields.buffers ? (
                    <div className="flex gap-3">
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-buf-before">
                                {t('admin.services.field.buffer_before')}
                            </Label>
                            <Input
                                id="svc-buf-before"
                                type="number"
                                min={0}
                                value={form.data.buffer_before_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'buffer_before_minutes',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-buf-after">
                                {t('admin.services.field.buffer_after')}
                            </Label>
                            <Input
                                id="svc-buf-after"
                                type="number"
                                min={0}
                                value={form.data.buffer_after_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'buffer_after_minutes',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                    </div>
                ) : null}

                {fields.capacity ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="svc-capacity">
                            {t('admin.services.field.capacity')}
                        </Label>
                        <Input
                            id="svc-capacity"
                            type="number"
                            min={1}
                            value={form.data.capacity}
                            onChange={(e) =>
                                form.setData(
                                    'capacity',
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                        />
                        {form.errors.capacity ? (
                            <p className="text-sm text-destructive">
                                {form.errors.capacity}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                {fields.fulfillment ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="svc-fulfillment">
                            {t('admin.services.field.fulfillment')}
                        </Label>
                        <select
                            id="svc-fulfillment"
                            value={form.data.fulfillment_type}
                            onChange={(e) =>
                                form.setData(
                                    'fulfillment_type',
                                    e.target.value as FulfillmentTypeValue,
                                )
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {FULFILLMENT_TYPES.map((type) => (
                                <option key={type} value={type}>
                                    {t(`admin.services.fulfillment.${type}`)}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : null}

                {fields.rental ? (
                    <div className="flex gap-3">
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-min">
                                {t('admin.services.field.min_duration')}
                            </Label>
                            <Input
                                id="svc-min"
                                type="number"
                                min={1}
                                value={form.data.min_duration_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'min_duration_minutes',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-max">
                                {t('admin.services.field.max_duration')}
                            </Label>
                            <Input
                                id="svc-max"
                                type="number"
                                min={1}
                                value={form.data.max_duration_minutes}
                                onChange={(e) =>
                                    form.setData(
                                        'max_duration_minutes',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                        <div className="flex flex-1 flex-col gap-2">
                            <Label htmlFor="svc-deposit">
                                {t('admin.services.field.deposit')}
                            </Label>
                            <Input
                                id="svc-deposit"
                                type="number"
                                min={0}
                                value={form.data.deposit}
                                onChange={(e) =>
                                    form.setData(
                                        'deposit',
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </div>
                    </div>
                ) : null}

                {fields.quote ? (
                    <fieldset className="flex flex-col gap-2">
                        <legend className="text-sm font-medium">
                            {t('admin.services.field.quote_fields')}
                        </legend>
                        {form.data.quote_fields.map((field, index) => (
                            <div key={index} className="flex gap-2">
                                <Input
                                    value={field}
                                    aria-label={t(
                                        'admin.services.field.quote_field_n',
                                        { n: index + 1 },
                                    )}
                                    onChange={(e) => {
                                        const next = [...form.data.quote_fields];
                                        next[index] = e.target.value;
                                        form.setData('quote_fields', next);
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                        form.setData(
                                            'quote_fields',
                                            form.data.quote_fields.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                    aria-label={t('admin.common.delete')}
                                >
                                    <Trash2Icon className="size-4 text-destructive" />
                                </Button>
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('quote_fields', [
                                    ...form.data.quote_fields,
                                    '',
                                ])
                            }
                        >
                            <PlusIcon className="size-4" />
                            {t('admin.services.add_quote_field')}
                        </Button>
                    </fieldset>
                ) : null}

                <div className="flex flex-col gap-2">
                    <Label htmlFor="svc-price">
                        {t('admin.services.field.price')}
                    </Label>
                    <Input
                        id="svc-price"
                        type="number"
                        min={0}
                        value={form.data.price}
                        onChange={(e) =>
                            form.setData(
                                'price',
                                e.target.value === '' ? '' : Number(e.target.value),
                            )
                        }
                    />
                </div>

                {/* Assignments */}
                {staff.length > 0 ? (
                    <fieldset className="flex flex-col gap-2 border-t border-border pt-4">
                        <legend className="text-sm font-medium">
                            {t('admin.services.field.staff')}
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {staff.map((member) => (
                                <label
                                    key={member.id}
                                    className="flex items-center gap-2 rounded-md border border-input px-2 py-1 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data.staff_ids.includes(
                                            member.id,
                                        )}
                                        onChange={() =>
                                            form.setData(
                                                'staff_ids',
                                                toggleId(
                                                    form.data.staff_ids,
                                                    member.id,
                                                ),
                                            )
                                        }
                                        className="size-4 rounded border-input"
                                    />
                                    {member.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ) : null}

                {rooms.length > 0 ? (
                    <fieldset className="flex flex-col gap-2">
                        <legend className="text-sm font-medium">
                            {t('admin.services.field.rooms')}
                        </legend>
                        <div className="flex flex-wrap gap-2">
                            {rooms.map((room) => (
                                <label
                                    key={room.id}
                                    className="flex items-center gap-2 rounded-md border border-input px-2 py-1 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data.room_ids.includes(room.id)}
                                        onChange={() =>
                                            form.setData(
                                                'room_ids',
                                                toggleId(form.data.room_ids, room.id),
                                            )
                                        }
                                        className="size-4 rounded border-input"
                                    />
                                    {room.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                ) : null}

                {/* Toggles */}
                <div className="flex flex-col gap-2 border-t border-border pt-4">
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.requires_staff}
                            onChange={(e) =>
                                form.setData('requires_staff', e.target.checked)
                            }
                            className="size-4 rounded border-input"
                        />
                        {t('admin.services.field.requires_staff')}
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.requires_room}
                            onChange={(e) =>
                                form.setData('requires_room', e.target.checked)
                            }
                            className="size-4 rounded border-input"
                        />
                        {t('admin.services.field.requires_room')}
                    </label>

                    {fields.waitlist && hasFeature('feature_waitlist') ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.waitlist_enabled}
                                onChange={(e) =>
                                    form.setData('waitlist_enabled', e.target.checked)
                                }
                                className="size-4 rounded border-input"
                            />
                            {t('admin.services.field.waitlist')}
                        </label>
                    ) : null}

                    {hasFeature('feature_approval_flow') ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.requires_approval}
                                onChange={(e) =>
                                    form.setData(
                                        'requires_approval',
                                        e.target.checked,
                                    )
                                }
                                className="size-4 rounded border-input"
                            />
                            {t('admin.services.field.requires_approval')}
                        </label>
                    ) : null}

                    {hasFeature('feature_online_payment') ? (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.online_payment_required}
                                onChange={(e) =>
                                    form.setData(
                                        'online_payment_required',
                                        e.target.checked,
                                    )
                                }
                                className="size-4 rounded border-input"
                            />
                            {t('admin.services.field.online_payment')}
                        </label>
                    ) : null}

                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.active}
                            onChange={(e) =>
                                form.setData('active', e.target.checked)
                            }
                            className="size-4 rounded border-input"
                        />
                        {t('admin.services.field.active')}
                    </label>
                </div>
            </FormSheet>

            {/* Category create/edit */}
            <FormSheet
                open={categorySheetOpen}
                onOpenChange={setCategorySheetOpen}
                title={
                    editingCategory
                        ? t('admin.services.category.edit_title')
                        : t('admin.services.category.new_title')
                }
                description={t('admin.services.category.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitCategory}
                submitting={categoryForm.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="cat-name">
                        {t('admin.services.category.field.name')}
                    </Label>
                    <Input
                        id="cat-name"
                        value={categoryForm.data.name}
                        onChange={(e) => categoryForm.setData('name', e.target.value)}
                        autoFocus
                    />
                    {categoryForm.errors.name ? (
                        <p className="text-sm text-destructive">
                            {categoryForm.errors.name}
                        </p>
                    ) : null}
                </div>
            </FormSheet>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={t('admin.services.delete_title')}
                description={t('admin.services.delete_body', {
                    name: deleting?.name ?? '',
                })}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDelete}
                destructive
            />

            <ConfirmDialog
                open={deletingCategory !== null}
                onOpenChange={(open) => !open && setDeletingCategory(null)}
                title={t('admin.services.category.delete_title')}
                description={t('admin.services.category.delete_body', {
                    name: deletingCategory?.name ?? '',
                })}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDeleteCategory}
                destructive
            />
        </AdminLayout>
    );
}
