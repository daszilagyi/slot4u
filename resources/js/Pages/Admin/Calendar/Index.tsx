import {
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    pointerWithin,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragMoveEvent,
    type DragStartEvent,
    type KeyboardCoordinateGetter,
} from '@dnd-kit/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarRangeIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    GripVerticalIcon,
} from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { toast } from 'sonner';

import AdminLayout from '@/Layouts/AdminLayout';
import EmptyState from '@/components/admin/EmptyState';
import PageHeader from '@/components/admin/PageHeader';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { bookingStatusClass } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import { useLiveBookings } from '@/lib/useLiveBookings';
import { cn } from '@/lib/utils';
import type {
    BookingCalendar,
    CalendarAbilities,
    CalendarColumn,
    CalendarEvent,
    CalendarFilters,
    CalendarOptions,
} from '@/types';

type CalendarProps = {
    calendar: BookingCalendar;
    filters: CalendarFilters;
    options: CalendarOptions;
    can: CalendarAbilities;
};

/** Pixels per hour of grid. Drives the whole vertical scale. */
const HOUR_HEIGHT = 56;

/** Dragging snaps to this grid, and one arrow key press moves exactly this much. */
const SNAP_MINUTES = 15;

const MINUTES_PER_DAY = 24 * 60;

/** A move the admin has proposed by dropping a card, awaiting confirmation. */
type PendingMove = {
    event: CalendarEvent;
    column: CalendarColumn;
    /** Tenant-local day the booking lands on (YYYY-MM-DD). */
    date: string;
    /** Wall-clock minutes from that day's midnight. */
    startMinute: number;
};

/**
 * Admin calendar (SLO-44, docs/05 M7). The server hands over every event already
 * expressed as wall-clock minutes from its own tenant-local midnight, so this file
 * never touches a timezone: it only turns minutes into pixels.
 *
 * Overlapping bookings are packed into lanes per column — the first lane whose last
 * event has ended takes the next one — so a double-booked slot shows both cards side
 * by side instead of hiding one under the other.
 *
 * Cards can be dragged to another slot. The drag itself only produces a *proposal*:
 * the move goes through the same `POST /bookings/{id}/reschedule` endpoint the list
 * page uses, so the conflict check, the state machine and the permission all stay on
 * the server. A refused move leaves the grid untouched — there is no optimistic
 * mutation to roll back, which is exactly why a taken slot "snaps back" for free.
 */
export default function CalendarIndex({
    calendar,
    filters,
    options,
    can,
}: CalendarProps) {
    const t = useTranslations();
    const { tenant } = usePage().props;

    const [dragging, setDragging] = useState<CalendarEvent | null>(null);
    const [preview, setPreview] = useState<PendingMove | null>(null);
    const [pending, setPending] = useState<PendingMove | null>(null);
    const [notify, setNotify] = useState(true);
    const [saving, setSaving] = useState(false);

    // A booking created or moved elsewhere (another admin, a public booking) should
    // appear here without a manual refresh. Re-pull only the calendar prop from the
    // existing Reverb feed rather than mutating local state, so what the grid shows
    // is always what the server just computed (same approach as the dashboard).
    const reload = useCallback(() => {
        router.reload({ only: ['calendar'] });
    }, []);
    useLiveBookings(tenant?.id, reload);

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

    const sensors = useSensors(
        // A small distance threshold keeps a plain click on the handle from
        // starting a drag, and leaves the card's own link clickable.
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: keyboardCoordinates }),
    );

    /**
     * Turn a drag into a proposed slot. Vertical movement is a pure pixel delta —
     * the grid is linear in minutes — so the new start is the old one plus the
     * snapped delta, clamped inside the day. The column comes from whatever the
     * pointer is over; `null` means the card was dropped outside the grid.
     */
    function proposeMove(
        event: CalendarEvent,
        columnKey: string | null,
        deltaY: number,
    ): PendingMove | null {
        const column =
            calendar.columns.find((candidate) => candidate.key === columnKey) ??
            null;

        // The "unassigned" column has no resource behind it: dropping there would
        // mean *clearing* the booking's staff/room, which is not a scheduling move.
        if (column === null || (calendar.view === 'day' && column.resource_id === null)) {
            return null;
        }

        const shift =
            Math.round(deltaY / ((SNAP_MINUTES / 60) * HOUR_HEIGHT)) *
            SNAP_MINUTES;
        const length = event.end_minute - event.start_minute;
        const startMinute = Math.min(
            Math.max(event.start_minute + shift, 0),
            MINUTES_PER_DAY - Math.max(length, SNAP_MINUTES),
        );

        return {
            event,
            column,
            // In week view the column *is* the day; in day view the columns are
            // resources and the day is the one the grid is anchored on.
            date: column.date ?? calendar.date,
            startMinute,
        };
    }

    function handleDragStart(event: DragStartEvent) {
        const dragged = calendar.events.find(
            (candidate) => candidate.id === Number(event.active.id),
        );
        setDragging(dragged ?? null);
        setPreview(null);
    }

    function handleDragMove(event: DragMoveEvent) {
        if (dragging === null) {
            return;
        }

        const next = proposeMove(
            dragging,
            event.over === null ? null : String(event.over.id),
            event.delta.y,
        );

        // Only re-render when the *snapped* target actually changed, not on every
        // pixel of pointer movement.
        setPreview((current) =>
            current?.date === next?.date &&
            current?.startMinute === next?.startMinute
                ? current
                : next,
        );
    }

    function handleDragEnd(event: DragEndEvent) {
        const dragged = dragging;
        setDragging(null);
        setPreview(null);

        if (dragged === null) {
            return;
        }

        const move = proposeMove(
            dragged,
            event.over === null ? null : String(event.over.id),
            event.delta.y,
        );

        // Dropped outside the grid, or back where it started — nothing to confirm.
        if (
            move === null ||
            (move.date === dragged.date &&
                move.startMinute === dragged.start_minute &&
                move.column.key === dragged.column_key)
        ) {
            return;
        }

        setNotify(true);
        setPending(move);
    }

    function submitMove() {
        if (pending === null) {
            return;
        }

        const { event, column, date, startMinute } = pending;
        // The server derives the new end from the length this booking already has,
        // in real elapsed time — adding wall-clock minutes here would silently
        // reshape the appointment when the move crosses a DST changeover.
        const payload: Record<string, string | number | boolean> = {
            starts_at: `${date}T${formatMinute(startMinute)}`,
            notify,
        };
        if (calendar.view === 'day' && column.resource_id !== null) {
            payload[filters.group === 'room' ? 'room_id' : 'staff_id'] =
                column.resource_id;
        }

        setSaving(true);
        router.post(`/bookings/${event.id}/reschedule`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setPending(null);
                toast.success(
                    t(
                        notify
                            ? 'admin.calendar.drag.moved'
                            : 'admin.calendar.drag.moved_silently',
                    ),
                );
            },
            onError: (errors) => {
                // The slot was taken (or the state machine refused): the whole
                // reschedule transaction rolled back, so the booking is still where
                // it was and the reloaded grid shows it there.
                toast.error(
                    errors.booking ??
                        errors.starts_at ??
                        errors.cancel ??
                        t('admin.calendar.drag.failed'),
                );
            },
            onFinish: () => setSaving(false),
        });
    }

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

    const draggable = can.edit;

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
                    <DndContext
                        sensors={sensors}
                        collisionDetection={pointerWithin}
                        onDragStart={handleDragStart}
                        onDragMove={handleDragMove}
                        onDragEnd={handleDragEnd}
                        onDragCancel={() => {
                            setDragging(null);
                            setPreview(null);
                        }}
                    >
                        <div className="overflow-x-auto rounded-2xl border border-border bg-card">
                            <div className="min-w-3xl">
                                {/* Column headers */}
                                <div
                                    className="grid border-b border-border"
                                    style={gridTemplate(
                                        calendar.columns.length,
                                    )}
                                >
                                    <div />
                                    {calendar.columns.map((column) => (
                                        <div
                                            key={column.key}
                                            className={cn(
                                                'border-l border-border px-3 py-2 text-center',
                                                column.date ===
                                                    calendar.today &&
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
                                    style={gridTemplate(
                                        calendar.columns.length,
                                    )}
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

                                    {calendar.columns.map((column) => (
                                        <ColumnDropZone
                                            key={column.key}
                                            column={column}
                                            calendar={calendar}
                                            height={gridHeight}
                                            hours={hours}
                                            placed={
                                                lanesByColumn.get(column.key) ??
                                                []
                                            }
                                            preview={preview}
                                            draggable={draggable}
                                            dragging={dragging}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>

                        <DragOverlay dropAnimation={null}>
                            {dragging !== null ? (
                                <div
                                    className={cn(
                                        'rounded-md border px-2 py-1 text-xs shadow-lg',
                                        bookingStatusClass(dragging.status),
                                    )}
                                >
                                    <div className="font-medium">
                                        {formatMinute(
                                            preview?.startMinute ??
                                                dragging.start_minute,
                                        )}{' '}
                                        · {dragging.service ?? dragging.code}
                                    </div>
                                </div>
                            ) : null}
                        </DragOverlay>
                    </DndContext>
                )}

                {draggable && calendar.columns.length > 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {t('admin.calendar.drag.hint')}
                    </p>
                ) : null}

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

            <MoveDialog
                move={pending}
                notify={notify}
                onNotifyChange={setNotify}
                saving={saving}
                onCancel={() => setPending(null)}
                onConfirm={submitMove}
                showResource={calendar.view === 'day'}
            />
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

/**
 * Arrow keys move a lifted card by one snap step vertically and one column
 * horizontally, instead of dnd-kit's default 25px — on a time grid the useful unit
 * is a quarter hour, not a pixel. The column width is measured from the drop zones
 * that are already registered, so it stays right at any viewport size.
 */
const keyboardCoordinates: KeyboardCoordinateGetter = (
    event,
    { currentCoordinates, context },
) => {
    const rowStep = (SNAP_MINUTES / 60) * HOUR_HEIGHT;
    const columnStep =
        [...context.droppableRects.values()][0]?.width ?? rowStep * 8;

    switch (event.code) {
        case 'ArrowUp':
            return { ...currentCoordinates, y: currentCoordinates.y - rowStep };
        case 'ArrowDown':
            return { ...currentCoordinates, y: currentCoordinates.y + rowStep };
        case 'ArrowLeft':
            return {
                ...currentCoordinates,
                x: currentCoordinates.x - columnStep,
            };
        case 'ArrowRight':
            return {
                ...currentCoordinates,
                x: currentCoordinates.x + columnStep,
            };
        default:
            return undefined;
    }
};

/**
 * One calendar column: the hour rules, the cards, and — while something is being
 * dragged — a ghost showing where it would land. The whole column is the drop
 * target; the exact minute comes from the drag's vertical delta, so the grid needs
 * no per-quarter-hour droppables.
 */
function ColumnDropZone({
    column,
    calendar,
    height,
    hours,
    placed,
    preview,
    draggable,
    dragging,
}: {
    column: CalendarColumn;
    calendar: BookingCalendar;
    height: number;
    hours: number[];
    placed: PlacedEvent[];
    preview: PendingMove | null;
    draggable: boolean;
    dragging: CalendarEvent | null;
}) {
    // An "unassigned" column in the resource-grouped day view has no resource to
    // move a booking to, so it never accepts a drop.
    const accepts =
        draggable && !(calendar.view === 'day' && column.resource_id === null);
    const { setNodeRef, isOver } = useDroppable({
        id: column.key,
        disabled: !accepts,
    });

    const showGhost =
        preview !== null && preview.column.key === column.key && accepts;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'relative border-l border-border',
                column.date === calendar.today && 'bg-primary/5',
                isOver && dragging !== null && 'bg-primary/10',
            )}
            style={{ height }}
        >
            {hours.slice(1).map((minute) => (
                <div
                    key={minute}
                    className="absolute inset-x-0 border-t border-border/50"
                    style={{
                        top:
                            ((minute - calendar.window_start_minute) / 60) *
                            HOUR_HEIGHT,
                    }}
                />
            ))}

            {showGhost && dragging !== null ? (
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-x-1 rounded-md border-2 border-dashed border-primary/70 bg-primary/10"
                    style={{
                        top:
                            ((preview.startMinute -
                                calendar.window_start_minute) /
                                60) *
                            HOUR_HEIGHT,
                        height: Math.max(
                            ((dragging.end_minute - dragging.start_minute) /
                                60) *
                                HOUR_HEIGHT,
                            20,
                        ),
                    }}
                />
            ) : null}

            {placed.map((event) => (
                <EventCard
                    key={event.id}
                    event={event}
                    windowStart={calendar.window_start_minute}
                    draggable={draggable}
                />
            ))}
        </div>
    );
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

/**
 * A booking on the grid. The card body stays a plain link (click or Enter opens the
 * booking); the drag lives on a separate handle, so opening a booking and moving it
 * never compete for the same gesture — and the handle carries dnd-kit's keyboard
 * bindings, which is what makes the move reachable without a mouse.
 */
function EventCard({
    event,
    windowStart,
    draggable,
}: {
    event: PlacedEvent;
    windowStart: number;
    draggable: boolean;
}) {
    const t = useTranslations();
    const movable = draggable && event.movable;

    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        isDragging,
    } = useDraggable({ id: event.id, disabled: !movable });

    const top = ((event.start_minute - windowStart) / 60) * HOUR_HEIGHT;
    const height = Math.max(
        ((event.end_minute - event.start_minute) / 60) * HOUR_HEIGHT,
        20,
    );
    const width = 100 / event.lanes;

    return (
        <div
            ref={setNodeRef}
            className={cn(
                'absolute overflow-hidden rounded-md border text-xs',
                bookingStatusClass(event.status),
                isDragging && 'opacity-40',
            )}
            style={{
                top,
                height,
                left: `calc(${event.lane * width}% + 2px)`,
                width: `calc(${width}% - 4px)`,
            }}
        >
            <Link
                href={`/bookings/${event.id}`}
                title={`${formatMinute(event.start_minute)}–${formatMinute(event.end_minute)} · ${event.service ?? event.code}`}
                className="block h-full px-2 py-1 transition-opacity hover:opacity-80"
            >
                <div className={cn('truncate font-medium', movable && 'pr-4')}>
                    {formatMinute(event.start_minute)} ·{' '}
                    {event.service ?? event.code}
                </div>
                <div className="truncate opacity-80">
                    {event.customer ?? t('admin.calendar.open_booking')}
                </div>
            </Link>

            {movable ? (
                <button
                    type="button"
                    ref={setActivatorNodeRef}
                    className="absolute right-0 top-0 flex h-5 w-4 cursor-grab touch-none items-center justify-center opacity-60 hover:opacity-100 focus-visible:opacity-100"
                    aria-label={t('admin.calendar.drag.handle', {
                        service: event.service ?? event.code,
                        time: formatMinute(event.start_minute),
                    })}
                    {...listeners}
                    {...attributes}
                >
                    <GripVerticalIcon className="size-3" />
                </button>
            ) : null}
        </div>
    );
}

/**
 * Confirmation for a proposed move (docs/04 §2 acceptance criteria). Nothing has
 * been sent yet at this point — cancelling simply drops the proposal and the card
 * stays where the server last put it.
 */
function MoveDialog({
    move,
    notify,
    onNotifyChange,
    saving,
    onCancel,
    onConfirm,
    showResource,
}: {
    move: PendingMove | null;
    notify: boolean;
    onNotifyChange: (value: boolean) => void;
    saving: boolean;
    onCancel: () => void;
    onConfirm: () => void;
    showResource: boolean;
}) {
    const t = useTranslations();

    if (move === null) {
        return null;
    }

    const resourceChanged = move.column.key !== move.event.column_key;

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    onCancel();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('admin.calendar.drag.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('admin.calendar.drag.question')}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">
                        {t('admin.calendar.drag.from')}
                    </dt>
                    <dd className="tabular-nums">
                        {move.event.date}{' '}
                        {formatMinute(move.event.start_minute)}
                    </dd>
                    <dt className="text-muted-foreground">
                        {t('admin.calendar.drag.to')}
                    </dt>
                    <dd className="font-medium tabular-nums">
                        {move.date} {formatMinute(move.startMinute)}
                    </dd>
                </dl>

                {showResource && resourceChanged ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.calendar.drag.resource_change', {
                            name: move.column.label,
                        })}
                    </p>
                ) : null}

                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={notify}
                        onChange={(event) =>
                            onNotifyChange(event.target.checked)
                        }
                        className="mt-0.5 size-4 rounded border-input"
                    />
                    <span>
                        <span className="font-medium">
                            {t('admin.calendar.drag.notify')}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            {t('admin.calendar.drag.notify_hint')}
                        </span>
                    </span>
                </label>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onCancel}
                        disabled={saving}
                    >
                        {t('admin.common.cancel')}
                    </Button>
                    <Button onClick={onConfirm} disabled={saving}>
                        {t('admin.calendar.drag.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
