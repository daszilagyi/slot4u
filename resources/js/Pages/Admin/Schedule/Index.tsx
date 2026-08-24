import { Head, router, useForm } from '@inertiajs/react';
import {
    AlertTriangleIcon,
    CalendarClockIcon,
    CopyIcon,
    PencilIcon,
    PlusIcon,
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
import { useTranslations } from '@/lib/i18n';
import type {
    Location,
    Schedulable,
    ScheduleBand,
    ScheduleConflict,
    ScheduleExceptionEntry,
    ScheduleExceptionTypeValue,
} from '@/types';

const DAYS = [1, 2, 3, 4, 5, 6, 7] as const;

type IndexProps = {
    schedulables: Schedulable[];
    locations: Pick<Location, 'id' | 'name'>[];
    schedules: ScheduleBand[];
    exceptions: ScheduleExceptionEntry[];
    exceptionTypes: ScheduleExceptionTypeValue[];
    conflicts: ScheduleConflict[];
    /** The lists hold the actor's own resources only (SLO-177, employee "saját"). */
    restricted: boolean;
};

const emptyBand = {
    day_of_week: 1 as number,
    start_time: '09:00',
    end_time: '17:00',
    location_id: '' as number | '',
    valid_from: '',
    valid_until: '',
};

type BandForm = typeof emptyBand;

const emptyException = {
    date: '',
    type: 'off' as ScheduleExceptionTypeValue,
    start_time: '',
    end_time: '',
    note: '',
};

function keyOf(s: { type: string; id: number }): string {
    return `${s.type}:${s.id}`;
}

export default function ScheduleIndex({
    schedulables,
    locations,
    schedules,
    exceptions,
    exceptionTypes,
    conflicts,
    restricted,
}: IndexProps) {
    const t = useTranslations();

    const [selectedKey, setSelectedKey] = useState<string>(
        schedulables.length > 0 ? keyOf(schedulables[0]) : '',
    );

    const selected = schedulables.find((s) => keyOf(s) === selectedKey) ?? null;

    const [bandSheetOpen, setBandSheetOpen] = useState(false);
    const [editingBand, setEditingBand] = useState<ScheduleBand | null>(null);
    const [deletingBand, setDeletingBand] = useState<ScheduleBand | null>(null);

    const [copyOpen, setCopyOpen] = useState(false);
    const [copySourceDay, setCopySourceDay] = useState<number>(1);
    const [copyTargets, setCopyTargets] = useState<number[]>([]);

    const [exceptionSheetOpen, setExceptionSheetOpen] = useState(false);
    const [deletingException, setDeletingException] =
        useState<ScheduleExceptionEntry | null>(null);

    const band = useForm<BandForm>({ ...emptyBand });
    const exception = useForm({ ...emptyException });

    // Bands / exceptions belonging to the selected resource only.
    const ownBands = useMemo(
        () =>
            selected
                ? schedules.filter(
                      (s) =>
                          s.schedulable_type === selected.type &&
                          s.schedulable_id === selected.id,
                  )
                : [],
        [schedules, selected],
    );

    const ownExceptions = useMemo(
        () =>
            selected
                ? exceptions.filter(
                      (e) =>
                          e.schedulable_type === selected.type &&
                          e.schedulable_id === selected.id,
                  )
                : [],
        [exceptions, selected],
    );

    const bandsByDay = useMemo(() => {
        const map = new Map<number, ScheduleBand[]>();
        for (const b of ownBands) {
            map.set(b.day_of_week, [...(map.get(b.day_of_week) ?? []), b]);
        }
        return map;
    }, [ownBands]);

    const staffOptions = schedulables.filter((s) => s.type === 'staff');
    const roomOptions = schedulables.filter((s) => s.type === 'room');

    // A band may only be scoped to a location the selected resource belongs to
    // (SLO-51): a staff's assigned locations, or a room's single location.
    const availableLocations = selected
        ? locations.filter((l) => selected.location_ids.includes(l.id))
        : [];

    function openCreateBand(day: number) {
        if (!selected) return;
        setEditingBand(null);
        band.clearErrors();
        band.setData({ ...emptyBand, day_of_week: day });
        setBandSheetOpen(true);
    }

    function openEditBand(entry: ScheduleBand) {
        setEditingBand(entry);
        band.clearErrors();
        band.setData({
            day_of_week: entry.day_of_week,
            start_time: entry.start_time,
            end_time: entry.end_time,
            location_id: entry.location_id ?? '',
            valid_from: entry.valid_from ?? '',
            valid_until: entry.valid_until ?? '',
        });
        setBandSheetOpen(true);
    }

    function submitBand(event: FormEvent) {
        event.preventDefault();
        if (!selected) return;

        band.transform((data) => ({
            schedulable_type: selected.type,
            schedulable_id: selected.id,
            day_of_week: data.day_of_week,
            start_time: data.start_time,
            end_time: data.end_time,
            location_id: data.location_id === '' ? null : data.location_id,
            valid_from: data.valid_from || null,
            valid_until: data.valid_until || null,
        }));

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(
                    editingBand
                        ? t('admin.schedule.band.updated')
                        : t('admin.schedule.band.created'),
                );
                setBandSheetOpen(false);
            },
        };

        if (editingBand) {
            band.put(`/schedule/entries/${editingBand.id}`, options);
        } else {
            band.post('/schedule/entries', options);
        }
    }

    function confirmDeleteBand() {
        if (!deletingBand) return;
        router.delete(`/schedule/entries/${deletingBand.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.schedule.band.deleted')),
        });
    }

    function openCopy(day: number) {
        setCopySourceDay(day);
        setCopyTargets([]);
        setCopyOpen(true);
    }

    function submitCopy(event: FormEvent) {
        event.preventDefault();
        if (!selected || copyTargets.length === 0) return;
        router.post(
            '/schedule/copy-day',
            {
                schedulable_type: selected.type,
                schedulable_id: selected.id,
                source_day: copySourceDay,
                target_days: copyTargets,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(t('admin.schedule.copied'));
                    setCopyOpen(false);
                },
            },
        );
    }

    function openCreateException() {
        exception.clearErrors();
        exception.setData({ ...emptyException });
        setExceptionSheetOpen(true);
    }

    function submitException(event: FormEvent) {
        event.preventDefault();
        if (!selected) return;

        exception.transform((data) => ({
            schedulable_type: selected.type,
            schedulable_id: selected.id,
            date: data.date,
            type: data.type,
            start_time: data.start_time || null,
            end_time: data.end_time || null,
            note: data.note || null,
        }));

        exception.post('/schedule/exceptions', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success(t('admin.schedule.exception.created'));
                setExceptionSheetOpen(false);
            },
        });
    }

    function confirmDeleteException() {
        if (!deletingException) return;
        router.delete(`/schedule/exceptions/${deletingException.id}`, {
            preserveScroll: true,
            onSuccess: () => toast.success(t('admin.schedule.exception.deleted')),
        });
    }

    function toggleCopyTarget(day: number) {
        setCopyTargets((prev) =>
            prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day],
        );
    }

    if (schedulables.length === 0) {
        return (
            <AdminLayout breadcrumbs={[{ label: t('admin.schedule.title') }]}>
                <Head title={t('admin.schedule.title')} />
                <PageHeader
                    title={t('admin.schedule.title')}
                    description={t('admin.schedule.subtitle')}
                />
                <div className="mt-6">
                    <EmptyState
                        icon={CalendarClockIcon}
                        title={t(
                            restricted
                                ? 'admin.schedule.empty_scoped_title'
                                : 'admin.schedule.empty_title',
                        )}
                        description={t(
                            restricted
                                ? 'admin.schedule.empty_scoped_body'
                                : 'admin.schedule.empty_body',
                        )}
                    />
                </div>
            </AdminLayout>
        );
    }

    return (
        <AdminLayout breadcrumbs={[{ label: t('admin.schedule.title') }]}>
            <Head title={t('admin.schedule.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.schedule.title')}
                    description={t('admin.schedule.subtitle')}
                    actions={
                        <div className="flex flex-col gap-2">
                            <Label htmlFor="sched-resource" className="text-xs">
                                {t('admin.schedule.resource_label')}
                            </Label>
                            <select
                                id="sched-resource"
                                value={selectedKey}
                                onChange={(e) => setSelectedKey(e.target.value)}
                                className="h-9 min-w-56 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                {/* An empty optgroup still renders its label, so a
                                    scoped actor would read a "Rooms" heading with
                                    nothing under it (SLO-177). */}
                                {staffOptions.length > 0 ? (
                                    <optgroup label={t('admin.schedule.group_staff')}>
                                        {staffOptions.map((s) => (
                                            <option key={keyOf(s)} value={keyOf(s)}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ) : null}
                                {roomOptions.length > 0 ? (
                                    <optgroup label={t('admin.schedule.group_rooms')}>
                                        {roomOptions.map((s) => (
                                            <option key={keyOf(s)} value={keyOf(s)}>
                                                {s.location_name
                                                    ? `${s.name} · ${s.location_name}`
                                                    : s.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ) : null}
                            </select>
                        </div>
                    }
                />

                {conflicts.length > 0 ? (
                    <div
                        role="alert"
                        className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4"
                    >
                        <div className="flex items-center gap-2 font-medium text-amber-700 dark:text-amber-400">
                            <AlertTriangleIcon className="size-4" />
                            {t('admin.schedule.conflict.title')}
                        </div>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('admin.schedule.conflict.body')}
                        </p>
                        <ul className="mt-2 flex flex-col gap-1 text-sm">
                            {conflicts.map((c) => (
                                <li key={c.id}>
                                    {t('admin.schedule.conflict.item', {
                                        code: c.code ?? `#${c.id}`,
                                        starts_at: c.starts_at,
                                    })}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {/* Weekly grid */}
                <section className="rounded-2xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between gap-2">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {t('admin.schedule.weekly_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.schedule.weekly_subtitle')}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-col divide-y divide-border">
                        {DAYS.map((day) => {
                            const dayBands = (bandsByDay.get(day) ?? []).sort(
                                (a, b) => a.start_time.localeCompare(b.start_time),
                            );
                            return (
                                <div
                                    key={day}
                                    className="flex flex-wrap items-start gap-3 py-3"
                                >
                                    <div className="w-28 shrink-0 pt-1.5 font-medium">
                                        {t(`admin.schedule.day.${day}`)}
                                    </div>
                                    <div className="flex flex-1 flex-wrap items-center gap-2">
                                        {dayBands.length === 0 ? (
                                            <span className="py-1.5 text-sm text-muted-foreground">
                                                {t('admin.schedule.no_bands')}
                                            </span>
                                        ) : (
                                            dayBands.map((entry) => (
                                                <span
                                                    key={entry.id}
                                                    className="flex items-center gap-1 rounded-md border border-input py-1 pl-2.5 pr-1 text-sm"
                                                >
                                                    <span className="font-medium tabular-nums">
                                                        {t(
                                                            'admin.schedule.band.range',
                                                            {
                                                                start: entry.start_time,
                                                                end: entry.end_time,
                                                            },
                                                        )}
                                                    </span>
                                                    {entry.location_id ? (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-1"
                                                        >
                                                            {locations.find(
                                                                (l) =>
                                                                    l.id ===
                                                                    entry.location_id,
                                                            )?.name ?? ''}
                                                        </Badge>
                                                    ) : null}
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7"
                                                        onClick={() =>
                                                            openEditBand(entry)
                                                        }
                                                        aria-label={t(
                                                            'admin.common.edit',
                                                        )}
                                                    >
                                                        <PencilIcon className="size-3.5" />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-7"
                                                        onClick={() =>
                                                            setDeletingBand(entry)
                                                        }
                                                        aria-label={t(
                                                            'admin.common.delete',
                                                        )}
                                                    >
                                                        <Trash2Icon className="size-3.5 text-destructive" />
                                                    </Button>
                                                </span>
                                            ))
                                        )}
                                    </div>
                                    <div className="flex items-center gap-1">
                                        {dayBands.length > 0 ? (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => openCopy(day)}
                                            >
                                                <CopyIcon className="size-4" />
                                                {t('admin.schedule.copy_day')}
                                            </Button>
                                        ) : null}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => openCreateBand(day)}
                                        >
                                            <PlusIcon className="size-4" />
                                            {t('admin.schedule.add_band')}
                                        </Button>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                {/* Exceptions */}
                <section className="rounded-2xl border border-border bg-card p-5">
                    <div className="mb-4 flex items-center justify-between gap-2">
                        <div>
                            <h2 className="text-lg font-semibold">
                                {t('admin.schedule.exceptions_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.schedule.exceptions_subtitle')}
                            </p>
                        </div>
                        <Button variant="outline" onClick={openCreateException}>
                            <PlusIcon className="size-4" />
                            {t('admin.schedule.add_exception')}
                        </Button>
                    </div>

                    {ownExceptions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.schedule.no_exceptions')}
                        </p>
                    ) : (
                        <ul className="flex flex-col divide-y divide-border">
                            {ownExceptions.map((e) => (
                                <li
                                    key={e.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2.5"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium tabular-nums">
                                            {e.date}
                                        </span>
                                        <Badge
                                            variant={
                                                e.type === 'off'
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {t(`admin.schedule.type.${e.type}`)}
                                        </Badge>
                                        <span className="text-sm text-muted-foreground">
                                            {e.start_time && e.end_time
                                                ? t('admin.schedule.band.range', {
                                                      start: e.start_time,
                                                      end: e.end_time,
                                                  })
                                                : t('admin.schedule.exception.all_day')}
                                        </span>
                                        {e.note ? (
                                            <span className="text-sm text-muted-foreground">
                                                — {e.note}
                                            </span>
                                        ) : null}
                                    </div>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => setDeletingException(e)}
                                        aria-label={t('admin.common.delete')}
                                    >
                                        <Trash2Icon className="size-4 text-destructive" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            {/* Band create/edit */}
            <FormSheet
                open={bandSheetOpen}
                onOpenChange={setBandSheetOpen}
                title={
                    editingBand
                        ? t('admin.schedule.band.edit_title')
                        : t('admin.schedule.band.new_title')
                }
                description={t('admin.schedule.band.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitBand}
                submitting={band.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="band-day">{t('admin.schedule.field.day')}</Label>
                    <select
                        id="band-day"
                        value={band.data.day_of_week}
                        onChange={(e) =>
                            band.setData('day_of_week', Number(e.target.value))
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {DAYS.map((day) => (
                            <option key={day} value={day}>
                                {t(`admin.schedule.day.${day}`)}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="band-start">
                            {t('admin.schedule.field.start')}
                        </Label>
                        <Input
                            id="band-start"
                            type="time"
                            value={band.data.start_time}
                            onChange={(e) =>
                                band.setData('start_time', e.target.value)
                            }
                        />
                        {band.errors.start_time ? (
                            <p className="text-sm text-destructive">
                                {band.errors.start_time}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="band-end">
                            {t('admin.schedule.field.end')}
                        </Label>
                        <Input
                            id="band-end"
                            type="time"
                            value={band.data.end_time}
                            onChange={(e) => band.setData('end_time', e.target.value)}
                        />
                        {band.errors.end_time ? (
                            <p className="text-sm text-destructive">
                                {band.errors.end_time}
                            </p>
                        ) : null}
                    </div>
                </div>

                {availableLocations.length > 0 ? (
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="band-location">
                            {t('admin.schedule.field.location')}
                        </Label>
                        <select
                            id="band-location"
                            value={band.data.location_id}
                            onChange={(e) =>
                                band.setData(
                                    'location_id',
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                                )
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            <option value="">
                                {t('admin.schedule.all_locations')}
                            </option>
                            {availableLocations.map((l) => (
                                <option key={l.id} value={l.id}>
                                    {l.name}
                                </option>
                            ))}
                        </select>
                        {band.errors.location_id ? (
                            <p className="text-sm text-destructive">
                                {band.errors.location_id}
                            </p>
                        ) : null}
                    </div>
                ) : null}

                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="band-from">
                            {t('admin.schedule.field.valid_from')}
                        </Label>
                        <Input
                            id="band-from"
                            type="date"
                            value={band.data.valid_from}
                            onChange={(e) =>
                                band.setData('valid_from', e.target.value)
                            }
                        />
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="band-until">
                            {t('admin.schedule.field.valid_until')}
                        </Label>
                        <Input
                            id="band-until"
                            type="date"
                            value={band.data.valid_until}
                            onChange={(e) =>
                                band.setData('valid_until', e.target.value)
                            }
                        />
                        {band.errors.valid_until ? (
                            <p className="text-sm text-destructive">
                                {band.errors.valid_until}
                            </p>
                        ) : null}
                    </div>
                </div>
            </FormSheet>

            {/* Copy day */}
            <FormSheet
                open={copyOpen}
                onOpenChange={setCopyOpen}
                title={t('admin.schedule.copy_title')}
                description={t('admin.schedule.copy_desc', {
                    day: t(`admin.schedule.day.${copySourceDay}`),
                })}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitCopy}
            >
                <fieldset className="flex flex-col gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        {t('admin.schedule.copy_targets')}
                    </legend>
                    <div className="flex flex-wrap gap-2">
                        {DAYS.filter((d) => d !== copySourceDay).map((day) => (
                            <label
                                key={day}
                                className="flex items-center gap-2 rounded-md border border-input px-2.5 py-1.5 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    checked={copyTargets.includes(day)}
                                    onChange={() => toggleCopyTarget(day)}
                                    className="size-4 rounded border-input"
                                />
                                {t(`admin.schedule.day.${day}`)}
                            </label>
                        ))}
                    </div>
                </fieldset>
            </FormSheet>

            {/* Exception create */}
            <FormSheet
                open={exceptionSheetOpen}
                onOpenChange={setExceptionSheetOpen}
                title={t('admin.schedule.exception.new_title')}
                description={t('admin.schedule.exception.form_desc')}
                submitLabel={t('admin.common.save')}
                cancelLabel={t('admin.common.cancel')}
                onSubmit={submitException}
                submitting={exception.processing}
            >
                <div className="flex flex-col gap-2">
                    <Label htmlFor="exc-date">
                        {t('admin.schedule.field.date')}
                    </Label>
                    <Input
                        id="exc-date"
                        type="date"
                        value={exception.data.date}
                        onChange={(e) => exception.setData('date', e.target.value)}
                    />
                    {exception.errors.date ? (
                        <p className="text-sm text-destructive">
                            {exception.errors.date}
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="exc-type">
                        {t('admin.schedule.field.type')}
                    </Label>
                    <select
                        id="exc-type"
                        value={exception.data.type}
                        onChange={(e) =>
                            exception.setData(
                                'type',
                                e.target.value as ScheduleExceptionTypeValue,
                            )
                        }
                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {exceptionTypes.map((type) => (
                            <option key={type} value={type}>
                                {t(`admin.schedule.type.${type}`)}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex gap-3">
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="exc-start">
                            {t('admin.schedule.field.start')}
                        </Label>
                        <Input
                            id="exc-start"
                            type="time"
                            value={exception.data.start_time}
                            onChange={(e) =>
                                exception.setData('start_time', e.target.value)
                            }
                        />
                        {exception.errors.start_time ? (
                            <p className="text-sm text-destructive">
                                {exception.errors.start_time}
                            </p>
                        ) : null}
                    </div>
                    <div className="flex flex-1 flex-col gap-2">
                        <Label htmlFor="exc-end">
                            {t('admin.schedule.field.end')}
                        </Label>
                        <Input
                            id="exc-end"
                            type="time"
                            value={exception.data.end_time}
                            onChange={(e) =>
                                exception.setData('end_time', e.target.value)
                            }
                        />
                        {exception.errors.end_time ? (
                            <p className="text-sm text-destructive">
                                {exception.errors.end_time}
                            </p>
                        ) : null}
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('admin.schedule.exception.whole_day_hint')}
                </p>

                <div className="flex flex-col gap-2">
                    <Label htmlFor="exc-note">
                        {t('admin.schedule.field.note')}
                    </Label>
                    <Input
                        id="exc-note"
                        value={exception.data.note}
                        onChange={(e) => exception.setData('note', e.target.value)}
                    />
                </div>
            </FormSheet>

            <ConfirmDialog
                open={deletingBand !== null}
                onOpenChange={(open) => !open && setDeletingBand(null)}
                title={t('admin.schedule.band.delete_title')}
                description={t('admin.schedule.band.delete_body')}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDeleteBand}
                destructive
            />

            <ConfirmDialog
                open={deletingException !== null}
                onOpenChange={(open) => !open && setDeletingException(null)}
                title={t('admin.schedule.exception.delete_title')}
                description={t('admin.schedule.exception.delete_body')}
                confirmLabel={t('admin.common.delete')}
                cancelLabel={t('admin.common.cancel')}
                onConfirm={confirmDeleteException}
                destructive
            />
        </AdminLayout>
    );
}
