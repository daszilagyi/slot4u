import { Head, Link, router } from '@inertiajs/react';
import { CalendarRangeIcon, ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { useMemo } from 'react';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { bookingStatusClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type {
    BookingCalendar,
    CalendarEvent,
    CalendarFilters,
    CalendarOptions,
} from '@/types';

type CalendarProps = {
    calendar: BookingCalendar;
    filters: CalendarFilters;
    options: CalendarOptions;
};

/** Pixels per hour of grid. Drives the whole vertical scale. */
const HOUR_HEIGHT = 56;

/**
 * Admin calendar (SLO-44, docs/05 M7). The server hands over every event already
 * expressed as wall-clock minutes from its own tenant-local midnight, so this file
 * never touches a timezone: it only turns minutes into pixels.
 *
 * Overlapping bookings are packed into lanes per column — the first lane whose last
 * event has ended takes the next one — so a double-booked slot shows both cards side
 * by side instead of hiding one under the other.
 */
export default function CalendarIndex({
    calendar,
    filters,
    options,
}: CalendarProps) {
    const t = useTranslations();

    const windowMinutes =
        calendar.window_end_minute - calendar.window_start_minute;
    const gridHeight = (windowMinutes / 60) * HOUR_HEIGHT;

    const hours = useMemo(() => {
        const list: number[] = [];
        for (
            let minute = calendar.window_start_minute;
            minute <= calendar.window_end_minute;
            minute += 60
        ) {
            list.push(minute);
        }

        return list;
    }, [calendar.window_start_minute, calendar.window_end_minute]);

    /** Events grouped by column, each already assigned a lane. */
    const lanesByColumn = useMemo(() => {
        const map = new Map<string, PlacedEvent[]>();
        for (const column of calendar.columns) {
            map.set(
                column.key,
                packLanes(
                    calendar.events.filter(
                        (event) => event.column_key === column.key,
                    ),
                ),
            );
        }

        return map;
    }, [calendar.columns, calendar.events]);

    function navigate(changes: Partial<CalendarFilters>) {
        const next = { ...filters, ...changes };
        router.get(
            '/calendar',
            {
                view: next.view,
                group: next.group,
                date: next.date ?? undefined,
                staff_id: next.staff_id ?? undefined,
                room_id: next.room_id ?? undefined,
                service_id: next.service_id ?? undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const hasFilter =
        filters.staff_id !== null ||
        filters.room_id !== null ||
        filters.service_id !== null;

    return (
        <AdminLayout>
            <Head title={t('admin.calendar.title')} />

            <div className="flex flex-col gap-6">
                <PageHeader
                    title={t('admin.calendar.title')}
                    description={t('admin.calendar.subtitle')}
                    actions={
                        <div className="flex items-center gap-1 rounded-lg border border-border p-1">
                            {(['day', 'week'] as const).map((view) => (
                                <Button
                                    key={view}
                                    type="button"
                                    size="sm"
                                    variant={
                                        calendar.view === view
                                            ? 'default'
                                            : 'ghost'
                                    }
                                    onClick={() => navigate({ view })}
                                >
                                    {t(`admin.calendar.view_${view}`)}
                                </Button>
                            ))}
                        </div>
                    }
                />

                <div className="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card p-4">
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label={t('admin.calendar.prev')}
                            onClick={() =>
                                navigate({ date: calendar.prev_date })
                            }
                        >
                            <ChevronLeftIcon className="size-4" />
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => navigate({ date: calendar.today })}
                        >
                            {t('admin.calendar.today')}
                        </Button>
                        <Button
                            type="button"
                            size="icon"
                            variant="outline"
                            aria-label={t('admin.calendar.next')}
                            onClick={() =>
                                navigate({ date: calendar.next_date })
                            }
                        >
                            <ChevronRightIcon className="size-4" />
                        </Button>
                    </div>

                    <span className="text-sm font-medium">
                        {calendar.range_start === calendar.range_end
                            ? calendar.range_start
                            : `${calendar.range_start} – ${calendar.range_end}`}
                    </span>

                    {calendar.view === 'day' ? (
                        <div className="flex flex-col gap-1">
                            <Label htmlFor="calendar-group">
                                {t('admin.calendar.group_label')}
                            </Label>
                            <select
                                id="calendar-group"
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                                value={filters.group}
                                onChange={(event) =>
                                    navigate({
                                        group: event.target.value as
                                            | 'staff'
                                            | 'room',
                                    })
                                }
                            >
                                <option value="staff">
                                    {t('admin.calendar.group_staff')}
                                </option>
                                <option value="room">
                                    {t('admin.calendar.group_room')}
                                </option>
                            </select>
                        </div>
                    ) : null}

                    <FilterSelect
                        id="calendar-staff"
                        label={t('admin.bookings.field.staff')}
                        placeholder={t('admin.calendar.all_staff')}
                        value={filters.staff_id}
                        items={options.staff}
                        onChange={(staff_id) => navigate({ staff_id })}
                    />
                    <FilterSelect
                        id="calendar-room"
                        label={t('admin.bookings.field.room')}
                        placeholder={t('admin.calendar.all_rooms')}
                        value={filters.room_id}
                        items={options.rooms}
                        onChange={(room_id) => navigate({ room_id })}
                    />
                    <FilterSelect
                        id="calendar-service"
                        label={t('admin.bookings.field.service')}
                        placeholder={t('admin.calendar.all_services')}
                        value={filters.service_id}
                        items={options.services}
                        onChange={(service_id) => navigate({ service_id })}
                    />

                    {hasFilter ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() =>
                                navigate({
                                    staff_id: null,
                                    room_id: null,
                                    service_id: null,
                                })
                            }
                        >
                            {t('admin.calendar.clear')}
                        </Button>
                    ) : null}

                    <span className="ml-auto text-xs text-muted-foreground">
                        {t('admin.calendar.timezone_note', {
                            tz: calendar.timezone,
                        })}
                    </span>
                </div>

                {calendar.columns.length === 0 ? (
                    <EmptyState
                        icon={CalendarRangeIcon}
                        title={t('admin.calendar.empty')}
                    />
                ) : (
                    <div className="overflow-x-auto rounded-2xl border border-border bg-card">
                        <div className="min-w-3xl">
                            {/* Column headers */}
                            <div
                                className="grid border-b border-border"
                                style={gridTemplate(calendar.columns.length)}
                            >
                                <div />
                                {calendar.columns.map((column) => (
                                    <div
                                        key={column.key}
                                        className={cn(
                                            'border-l border-border px-3 py-2 text-center',
                                            column.date === calendar.today &&
                                                'bg-primary/5',
                                        )}
                                    >
                                        <div className="truncate text-sm font-medium">
                                            {column.date !== null
                                                ? t(
                                                      `admin.calendar.weekday.${column.label}`,
                                                  )
                                                : column.label}
                                        </div>
                                        {column.sublabel ? (
                                            <div className="text-xs text-muted-foreground">
                                                {column.sublabel}
                                            </div>
                                        ) : null}
                                    </div>
                                ))}
                            </div>

                            {/* Time grid */}
                            <div
                                className="grid"
                                style={gridTemplate(calendar.columns.length)}
                            >
                                <div
                                    className="relative"
                                    style={{ height: gridHeight }}
                                >
                                    {hours.map((minute) => (
                                        <div
                                            key={minute}
                                            className="absolute right-2 -translate-y-1/2 text-xs tabular-nums text-muted-foreground"
                                            style={{
                                                top:
                                                    ((minute -
                                                        calendar.window_start_minute) /
                                                        60) *
                                                    HOUR_HEIGHT,
                                            }}
                                        >
                                            {formatMinute(minute)}
                                        </div>
                                    ))}
                                </div>

                                {calendar.columns.map((column) => {
                                    const placed =
                                        lanesByColumn.get(column.key) ?? [];

                                    return (
                                        <div
                                            key={column.key}
                                            className={cn(
                                                'relative border-l border-border',
                                                column.date ===
                                                    calendar.today &&
                                                    'bg-primary/5',
                                            )}
                                            style={{ height: gridHeight }}
                                        >
                                            {hours.slice(1).map((minute) => (
                                                <div
                                                    key={minute}
                                                    className="absolute inset-x-0 border-t border-border/50"
                                                    style={{
                                                        top:
                                                            ((minute -
                                                                calendar.window_start_minute) /
                                                                60) *
                                                            HOUR_HEIGHT,
                                                    }}
                                                />
                                            ))}
                                            {placed.map((event) => (
                                                <EventCard
                                                    key={event.id}
                                                    event={event}
                                                    windowStart={
                                                        calendar.window_start_minute
                                                    }
                                                />
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    </div>
                )}

                {calendar.unscheduled.length > 0 ? (
                    <section className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-base font-medium">
                                {t('admin.calendar.unscheduled_title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.calendar.unscheduled_hint')}
                            </p>
                        </div>
                        <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {calendar.unscheduled.map((booking) => (
                                <li key={booking.id}>
                                    <Link
                                        href={`/bookings/${booking.id}`}
                                        className="flex items-center justify-between gap-2 rounded-xl border border-border p-3 text-sm transition-colors hover:border-primary/40"
                                    >
                                        <span className="truncate">
                                            {booking.service ?? booking.code}
                                            <span className="text-muted-foreground">
                                                {booking.customer
                                                    ? ` · ${booking.customer}`
                                                    : ''}
                                            </span>
                                        </span>
                                        <Badge
                                            variant="secondary"
                                            className="shrink-0"
                                        >
                                            {t(
                                                `booking_status.${booking.status}`,
                                            )}
                                        </Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                ) : null}
            </div>
        </AdminLayout>
    );
}

/** A time gutter plus N equal columns. */
function gridTemplate(columns: number) {
    return {
        gridTemplateColumns: `4rem repeat(${columns}, minmax(0, 1fr))`,
    };
}

function formatMinute(minute: number): string {
    const hour = Math.floor(minute / 60) % 24;
    const rest = minute % 60;

    return `${String(hour).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
}

type PlacedEvent = CalendarEvent & { lane: number; lanes: number };

/**
 * Greedy lane packing: walk the events in start order and drop each into the first
 * lane that is already free at that minute. Every event of one overlapping cluster
 * ends up reporting the same lane count, so their widths add up to the column.
 */
function packLanes(events: CalendarEvent[]): PlacedEvent[] {
    const sorted = [...events].sort(
        (a, b) => a.start_minute - b.start_minute || a.id - b.id,
    );

    const placed: PlacedEvent[] = [];
    // Events that overlap each other transitively; flushed once a gap appears.
    let cluster: PlacedEvent[] = [];
    let laneEnds: number[] = [];

    function flush() {
        for (const event of cluster) {
            event.lanes = laneEnds.length;
        }
        placed.push(...cluster);
        cluster = [];
        laneEnds = [];
    }

    for (const event of sorted) {
        // Nothing in the cluster is still running → it is a fresh, independent group.
        if (laneEnds.length > 0 && Math.max(...laneEnds) <= event.start_minute) {
            flush();
        }

        let lane = laneEnds.findIndex((end) => end <= event.start_minute);
        if (lane === -1) {
            lane = laneEnds.length;
        }
        laneEnds[lane] = event.end_minute;
        cluster.push({ ...event, lane, lanes: laneEnds.length });
    }
    flush();

    return placed;
}

function EventCard({
    event,
    windowStart,
}: {
    event: PlacedEvent;
    windowStart: number;
}) {
    const t = useTranslations();

    const top = ((event.start_minute - windowStart) / 60) * HOUR_HEIGHT;
    const height = Math.max(
        ((event.end_minute - event.start_minute) / 60) * HOUR_HEIGHT,
        20,
    );
    const width = 100 / event.lanes;

    return (
        <Link
            href={`/bookings/${event.id}`}
            title={`${formatMinute(event.start_minute)}–${formatMinute(event.end_minute)} · ${event.service ?? event.code}`}
            className={cn(
                'absolute overflow-hidden rounded-md border px-2 py-1 text-xs transition-opacity hover:opacity-80',
                bookingStatusClass(event.status),
            )}
            style={{
                top,
                height,
                left: `calc(${event.lane * width}% + 2px)`,
                width: `calc(${width}% - 4px)`,
            }}
        >
            <div className="truncate font-medium">
                {formatMinute(event.start_minute)} ·{' '}
                {event.service ?? event.code}
            </div>
            <div className="truncate opacity-80">
                {event.customer ?? t('admin.calendar.open_booking')}
            </div>
        </Link>
    );
}

function FilterSelect({
    id,
    label,
    placeholder,
    value,
    items,
    onChange,
}: {
    id: string;
    label: string;
    placeholder: string;
    value: number | null;
    items: { id: number; name: string }[];
    onChange: (value: number | null) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1">
            <Label htmlFor={id}>{label}</Label>
            <select
                id={id}
                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                value={value ?? ''}
                onChange={(event) =>
                    onChange(
                        event.target.value === ''
                            ? null
                            : Number(event.target.value),
                    )
                }
            >
                <option value="">{placeholder}</option>
                {items.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.name}
                    </option>
                ))}
            </select>
        </div>
    );
}
