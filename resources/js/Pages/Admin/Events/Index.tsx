import { Head, router, useForm } from '@inertiajs/react';
import {
    BanIcon,
    CalendarPlusIcon,
    PencilIcon,
    PlusIcon,
    RepeatIcon,
    Trash2Icon,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import FormSheet from '@/components/admin/FormSheet';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/lib/i18n';
import type { AssignableStaff, EventItem } from '@/types';

type NamedRef = { id: number; name: string };

type IndexProps = {
    events: EventItem[];
    services: NamedRef[];
    staff: AssignableStaff[];
    rooms: NamedRef[];
    waitlistEnabled: boolean;
    timezone: string;
};

type ScopeValue = 'this' | 'following';

const emptyForm = {
    service_id: '' as number | '',
    staff_id: '' as number | '',
    room_id: '' as number | '',
    starts_at: '',
    ends_at: '',
    capacity: 10 as number | '',
    waitlist_enabled: false,
    repeat_weekly: false,
    repeat_until: '',
    scope: 'this' as ScopeValue,
};

type EventForm = typeof emptyForm;

/** "2026-09-01T10:00" → "2026-09-01 10:00" for display. */
function displayDateTime(value: string): string {
    return value.replace('T', ' ');
}

export default function EventsIndex({
    events,
    services,
    staff,
    rooms,
    waitlistEnabled,
    timezone,
}: IndexProps) {
    const t = useTranslations();

    const [sheetOpen, setSheetOpen] = useState(false);
    const [editing, setEditing] = useState<EventItem | null>(null);
    const [canceling, setCanceling] = useState<EventItem | null>(null);
    const [deleting, setDeleting] = useState<EventItem | null>(null);

    const form = useForm<EventForm>({ ...emptyForm });
    const [cancelScope, setCancelScope] = useState<ScopeValue>('this');
    const [deleteScope, setDeleteScope] = useState<ScopeValue>('this');

    function openCreate() {
        setEditing(null);
        form.clearErrors();
        form.setDefaults({ ...emptyForm });
        form.reset();
        setSheetOpen(true);
    }

    function openEdit(event: EventItem) {
        setEditing(event);
        form.clearErrors();
        form.setData({
            service_id: event.service_id,
            staff_id: event.staff_id ?? '',
            room_id: event.room_id ?? '',
            starts_at: event.starts_at,
            ends_at: event.ends_at,
            capacity: event.capacity,
            waitlist_enabled: event.waitlist_enabled,
            repeat_weekly: false,
            repeat_until: '',
            scope: 'this',
        });
        setSheetOpen(true);
    }

    function submit(event: FormEvent) {
        event.preventDefault();

        form.transform((data) => ({
            service_id: data.service_id === '' ? null : data.service_id,
            staff_id: data.staff_id === '' ? null : data.staff_id,
            room_id: data.room_id === '' ? null : data.room_id,
            starts_at: data.starts_at,
            ends_at: data.ends_at,
            capacity: data.capacity === '' ? null : data.capacity,
            waitlist_enabled: data.waitlist_enabled,
            repeat_weekly: editing ? false : data.repeat_weekly,
            repeat_until: data.repeat_weekly ? data.repeat_until : null,
            scope: data.scope,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editing
                        ? t('admin.events.updated')
                        : t('admin.events.created'),
                );
                setSheetOpen(false);
            },
        };

        if (editing) {
            form.put(`/events/${editing.id}`, options);
        } else {
            form.post('/events', options);
        }
    }

    function confirmCancel(event: FormEvent) {
        event.preventDefault();
        if (!canceling) return;
        router.post(
            `/events/${canceling.id}/cancel`,
            { scope: canceling.is_recurring ? cancelScope : 'this' },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(t('admin.events.canceled_msg'));
                    setCanceling(null);
                },
            },
        );
    }

    function confirmDelete(event: FormEvent) {
        event.preventDefault();
        if (!deleting) return;
        router.delete(`/events/${deleting.id}`, {
            preserveScroll: true,
            data: { scope: deleting.is_recurring ? deleteScope : 'this' },
            onSuccess: () => {
                toast.success(t('admin.events.deleted'));
                setDeleting(null);
            },
            onError: (errors) =>
                toast.error(errors.delete ?? t('admin.events.error.delete_has_bookings')),
        });
    }

    const fields = form.data;

    if (services.length === 0 && events.length === 0) {
        return (
            <AdminLayout breadcrumbs={[{ label: t('admin.events.title') }]}>
                <Head title={t('admin.events.title')} />
                <PageHeader
                    title={t('admin.events.title')}
                    description={t('admin.events.subtitle')}
                />
                <div className="mt-6">
                    <EmptyState
                        icon={CalendarPlusIcon}
                        title={t('admin.events.no_services_title')}
                        description={t('admin.events.no_services_body')}
                    />
                </div>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.events.title') }]}>
            <Head title={t('admin.events.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.events.title')}
                    description={t('admin.events.subtitle')}
                    actions={
                        <Button onClick={openCreate} disabled={services.length === 0}>
                            <PlusIcon className="size-4" />
                            {t('admin.events.add')}
                        </Button>
                    }
                />

                <p className="text-sm text-muted-foreground">
                    {t('admin.events.tz_hint', { tz: timezone })}
                </p>

                {events.length === 0 ? (
                    <EmptyState
                        icon={CalendarPlusIcon}
                        title={t('admin.events.empty_title')}
                        description={t('admin.events.empty_body')}
                        action={
                            <Button onClick={openCreate}>
                                <PlusIcon className="size-4" />
                                {t('admin.events.add')}
                            </Button>
                        }
                    />
                ) : (
                    <ul className="flex flex-col gap-3">
                        {events.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card p-4"
                            >
                                <div className="flex flex-col gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold tabular-nums">
                                            {displayDateTime(event.starts_at)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            → {displayDateTime(event.ends_at)}
                                        </span>
                                        {event.is_recurring ? (
                                            <Badge variant="outline">
                                                <RepeatIcon className="size-3" />
                                                {t('admin.events.recurring')}
                                            </Badge>
                                        ) : null}
                                        {event.status === 'canceled' ? (
                                            <Badge variant="secondary">
                                                {t('admin.events.canceled_badge')}
                                            </Badge>
                                        ) : null}
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                        <span className="font-medium text-foreground">
                                            {event.service_name}
                                        </span>
                                        {event.staff_name ? (
                                            <span>· {event.staff_name}</span>
                                        ) : null}
                                        {event.room_name ? (
                                            <span>· {event.room_name}</span>
                                        ) : null}
                                        <Badge variant="outline">
                                            {t('admin.events.booked', {
                                                count: event.booked_count,
                                                capacity: event.capacity,
                                            })}
                                        </Badge>
                                    </div>
                                </div>
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => openEdit(event)}
                                        aria-label={t('admin.common.edit')}
                                    >
                                        <PencilIcon className="size-4" />
                                    </Button>
                                    {event.status !== 'canceled' ? (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => {
                                                setCancelScope('this');
                                                setCanceling(event);
                                            }}
                                            aria-label={t('admin.events.add_cancel')}
                                        >
                                            <BanIcon className="size-4" />
                                        </Button>
                                    ) : null}
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => {
                                            setDeleteScope('this');
                                            setDeleting(event);
                                        }}
                                        aria-label={t('admin.common.delete')}
                                    >
                                        <Trash2Icon className="size-4 text-destructive" />
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {/* Create / edit */}
            <FormSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                title={
                    editing
                        ? t('admin.events.edit_title')
                        : t('admin.events.new_title')
                }
                description={t('admin.events.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submit}
                submitting={form.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="ev-service">
                        {t('admin.events.field.service')}
                    </Label>
                    <select
                        id="ev-service"
                        value={fields.service_id}
                        onChange={(e) =>
                            form.setData(
                                'service_id',
                                e.target.value === '' ? '' : Number(e.target.value),
                            )
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <option value="">{t('admin.events.none')}</option>
                        {services.map((service) => (
                            <option key={service.id} value={service.id}>
                                {service.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.service_id ? (
                        <p className="text-sm text-destructive">
                            {form.errors.service_id}
                        </p>
                    ) : null}
                </div>

                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="ev-staff">
                            {t('admin.events.field.staff')}
                        </Label>
                        <select
                            id="ev-staff"
                            value={fields.staff_id}
                            onChange={(e) =>
                                form.setData(
                                    'staff_id',
                                    e.target.value === '' ? '' : Number(e.target.value),
                                )
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option value="">{t('admin.events.none')}</option>
                            {staff.map((member) => (
                                <option key={member.id} value={member.id}>
                                    {member.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="ev-room">
                            {t('admin.events.field.room')}
                        </Label>
                        <select
                            id="ev-room"
                            value={fields.room_id}
                            onChange={(e) =>
                                form.setData(
                                    'room_id',
                                    e.target.value === '' ? '' : Number(e.target.value),
                                )
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option value="">{t('admin.events.none')}</option>
                            {rooms.map((room) => (
                                <option key={room.id} value={room.id}>
                                    {room.name}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="ev-start">
                            {t('admin.events.field.starts_at')}
                        </Label>
                        <Input
                            id="ev-start"
                            type="datetime-local"
                            value={fields.starts_at}
                            onChange={(e) =>
                                form.setData('starts_at', e.target.value)
                            }
                        />
                        {form.errors.starts_at ? (
                            <p className="text-sm text-destructive">
                                {form.errors.starts_at}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="ev-end">
                            {t('admin.events.field.ends_at')}
                        </Label>
                        <Input
                            id="ev-end"
                            type="datetime-local"
                            value={fields.ends_at}
                            onChange={(e) => form.setData('ends_at', e.target.value)}
                        />
                        {form.errors.ends_at ? (
                            <p className="text-sm text-destructive">
                                {form.errors.ends_at}
                            </p>
                        ) : null}
                    </div>
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="ev-capacity">
                        {t('admin.events.field.capacity')}
                    </Label>
                    <Input
                        id="ev-capacity"
                        type="number"
                        min={1}
                        value={fields.capacity}
                        onChange={(e) =>
                            form.setData(
                                'capacity',
                                e.target.value === '' ? '' : Number(e.target.value),
                            )
                        }
                    />
                    {form.errors.capacity ? (
                        <p className="text-sm text-destructive">
                            {form.errors.capacity}
                        </p>
                    ) : null}
                </div>

                {waitlistEnabled ? (
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={fields.waitlist_enabled}
                            onChange={(e) =>
                                form.setData('waitlist_enabled', e.target.checked)
                            }
                            className="size-4 rounded border-input"
                        />
                        {t('admin.events.field.waitlist')}
                    </label>
                ) : null}

                {/* Recurrence — create only */}
                {!editing ? (
                    <div className="flex flex-col gap-2 border-t border-border pt-4">
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={fields.repeat_weekly}
                                onChange={(e) =>
                                    form.setData('repeat_weekly', e.target.checked)
                                }
                                className="size-4 rounded border-input"
                            />
                            {t('admin.events.field.repeat_weekly')}
                        </label>
                        {fields.repeat_weekly ? (
                            <div className="flex flex-col gap-2">
                                <Label htmlFor="ev-until">
                                    {t('admin.events.field.repeat_until')}
                                </Label>
                                <Input
                                    id="ev-until"
                                    type="date"
                                    value={fields.repeat_until}
                                    onChange={(e) =>
                                        form.setData('repeat_until', e.target.value)
                                    }
                                />
                                {form.errors.repeat_until ? (
                                    <p className="text-sm text-destructive">
                                        {form.errors.repeat_until}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {/* Scope — editing a recurring occurrence */}
                {editing?.is_recurring ? (
                    <fieldset className="flex flex-col gap-2 border-t border-border pt-4">
                        <legend className="text-sm font-medium">
                            {t('admin.events.scope.label')}
                        </legend>
                        {(['this', 'following'] as ScopeValue[]).map((scope) => (
                            <label
                                key={scope}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="scope"
                                    checked={fields.scope === scope}
                                    onChange={() => form.setData('scope', scope)}
                                    className="size-4 border-input"
                                />
                                {t(`admin.events.scope.${scope}`)}
                            </label>
                        ))}
                    </fieldset>
                ) : null}
            </FormSheet>

            {/* Cancel */}
            <FormSheet
                open={canceling !== null}
                onOpenChange={(open) => !open && setCanceling(null)}
                title={t('admin.events.cancel_title')}
                description={t('admin.events.cancel_body')}
                submitLabel={t('admin.events.add_cancel')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={confirmCancel}
            >
                {canceling?.is_recurring ? (
                    <ScopeRadio
                        name="cancel-scope"
                        legend={t('admin.events.scope.label')}
                        value={cancelScope}
                        onChange={setCancelScope}
                        label={(s) => t(`admin.events.scope.${s}`)}
                    />
                ) : null}
            </FormSheet>

            {/* Delete */}
            <FormSheet
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={t('admin.events.delete_title')}
                description={t('admin.events.delete_body')}
                submitLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={confirmDelete}
            >
                {deleting?.is_recurring ? (
                    <ScopeRadio
                        name="delete-scope"
                        legend={t('admin.events.scope.label')}
                        value={deleteScope}
                        onChange={setDeleteScope}
                        label={(s) => t(`admin.events.scope.${s}`)}
                    />
                ) : null}
            </FormSheet>
        </AdminLayout>
    );
}

function ScopeRadio({
    name,
    legend,
    value,
    onChange,
    label,
}: {
    name: string;
    legend: string;
    value: ScopeValue;
    onChange: (value: ScopeValue) => void;
    label: (scope: ScopeValue) => string;
}) {
    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="text-sm font-medium">{legend}</legend>
            {(['this', 'following'] as ScopeValue[]).map((scope) => (
                <label key={scope} className="flex items-center gap-2 text-sm">
                    <input
                        type="radio"
                        name={name}
                        checked={value === scope}
                        onChange={() => onChange(scope)}
                        className="size-4 border-input"
                    />
                    {label(scope)}
                </label>
            ))}
        </fieldset>
    );
}
