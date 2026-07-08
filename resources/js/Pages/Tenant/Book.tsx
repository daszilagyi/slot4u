import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeftIcon, ClockIcon } from 'lucide-react';
import { useState } from 'react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type {
    BookDay,
    BookLocationOption,
    BookRoomOption,
    BookServiceDetail,
    BookServiceOption,
    BookSlot,
    BookStaffOption,
} from '@/types';

type BookProps = {
    services: BookServiceOption[];
    service: BookServiceDetail | null;
    timezone: string;
    filters?: {
        staff: number | null;
        room: number | null;
        location: number | null;
    };
    week?: BookDay[];
    selected_date?: string;
    prev_week?: string;
    next_week?: string;
    is_first_week?: boolean;
    slots?: BookSlot[];
    staff_options?: BookStaffOption[];
    room_options?: BookRoomOption[];
    location_options?: BookLocationOption[];
};

/** The `start` query param (an ISO instant) marks the selected slot, if any. */
function selectedStartFrom(url: string): string | null {
    const query = url.split('?')[1] ?? '';
    return new URLSearchParams(query).get('start');
}

export default function Book(props: BookProps) {
    const { services, service, timezone } = props;
    const t = useTranslations();
    const page = usePage();
    const [navigating, setNavigating] = useState(false);

    const selectedStart = selectedStartFrom(page.url);
    const filters = props.filters ?? {
        staff: null,
        room: null,
        location: null,
    };

    // Every navigation is a partial reload that preserves scroll and shows a
    // loading state; the URL holds the full filter/date/slot state (back-safe).
    function go(params: Record<string, string | number | undefined>) {
        router.get('/book', params, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setNavigating(true),
            onFinish: () => setNavigating(false),
        });
    }

    function baseParams(): Record<string, string | number | undefined> {
        return {
            service: service?.id,
            location: filters.location ?? undefined,
            staff: filters.staff ?? undefined,
            room: filters.room ?? undefined,
            date: props.selected_date,
        };
    }

    const isSlotMode =
        service?.booking_mode === 'duration_based' ||
        service?.booking_mode === 'resource_rental';

    const locationOptions = props.location_options ?? [];
    const staffOptions = (props.staff_options ?? []).filter(
        (s) =>
            filters.location === null ||
            s.location_ids.includes(filters.location),
    );
    const roomOptions = (props.room_options ?? []).filter(
        (r) => filters.location === null || r.location_id === filters.location,
    );
    const slots = props.slots ?? [];
    const week = props.week ?? [];
    const selectedSlot = slots.find((s) => s.start === selectedStart) ?? null;

    return (
        <PublicLayout>
            <Head
                title={`${service?.name ?? t('tenant.book.title')} · ${t('tenant.book.title')}`}
            />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-10 sm:px-6">
                <Link
                    href="/"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeftIcon className="size-4" />
                    {t('tenant.book.back')}
                </Link>

                {service === null ? (
                    <p className="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                        {t('tenant.book.no_services')}
                    </p>
                ) : (
                    <>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <div className="flex flex-col gap-1">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {service.name}
                                </h1>
                                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                                    <span className="font-medium text-primary">
                                        {formatMoney(
                                            service.price_minor,
                                            service.currency,
                                        )}
                                    </span>
                                    {service.duration_minutes ? (
                                        <span className="inline-flex items-center gap-1">
                                            <ClockIcon className="size-3.5" />
                                            {t('tenant.book.minutes', {
                                                count: String(
                                                    service.duration_minutes,
                                                ),
                                            })}
                                        </span>
                                    ) : null}
                                </div>
                            </div>

                            {services.length > 1 ? (
                                <label className="flex flex-col gap-1 text-sm">
                                    <span className="text-muted-foreground">
                                        {t('tenant.book.choose_service')}
                                    </span>
                                    <select
                                        value={service.id}
                                        onChange={(e) =>
                                            go({
                                                service: Number(e.target.value),
                                            })
                                        }
                                        className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                                    >
                                        {services.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            ) : null}
                        </div>

                        {isSlotMode ? (
                            <>
                                {/* Filters */}
                                <div className="flex flex-wrap gap-3">
                                    {locationOptions.length > 1 ? (
                                        <label className="flex flex-col gap-1 text-sm">
                                            <span className="text-muted-foreground">
                                                {t(
                                                    'tenant.book.location_label',
                                                )}
                                            </span>
                                            <select
                                                value={filters.location ?? ''}
                                                onChange={(e) =>
                                                    go({
                                                        ...baseParams(),
                                                        location:
                                                            e.target.value ||
                                                            undefined,
                                                        staff: undefined,
                                                        room: undefined,
                                                    })
                                                }
                                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                                            >
                                                <option value="">
                                                    {t(
                                                        'tenant.book.all_locations',
                                                    )}
                                                </option>
                                                {locationOptions.map((l) => (
                                                    <option
                                                        key={l.id}
                                                        value={l.id}
                                                    >
                                                        {l.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    ) : null}

                                    {service.booking_mode ===
                                        'duration_based' &&
                                    staffOptions.length > 0 ? (
                                        <label className="flex flex-col gap-1 text-sm">
                                            <span className="text-muted-foreground">
                                                {t('tenant.book.staff_label')}
                                            </span>
                                            <select
                                                value={filters.staff ?? ''}
                                                onChange={(e) =>
                                                    go({
                                                        ...baseParams(),
                                                        staff:
                                                            e.target.value ||
                                                            undefined,
                                                    })
                                                }
                                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                                            >
                                                <option value="">
                                                    {t('tenant.book.anyone')}
                                                </option>
                                                {staffOptions.map((s) => (
                                                    <option
                                                        key={s.id}
                                                        value={s.id}
                                                    >
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    ) : null}

                                    {service.booking_mode ===
                                        'resource_rental' &&
                                    roomOptions.length > 0 ? (
                                        <label className="flex flex-col gap-1 text-sm">
                                            <span className="text-muted-foreground">
                                                {t('tenant.book.room_label')}
                                            </span>
                                            <select
                                                value={filters.room ?? ''}
                                                onChange={(e) =>
                                                    go({
                                                        ...baseParams(),
                                                        room:
                                                            e.target.value ||
                                                            undefined,
                                                    })
                                                }
                                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                                            >
                                                <option value="">
                                                    {t('tenant.book.any_room')}
                                                </option>
                                                {roomOptions.map((r) => (
                                                    <option
                                                        key={r.id}
                                                        value={r.id}
                                                    >
                                                        {r.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    ) : null}
                                </div>

                                {/* Week strip */}
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={props.is_first_week}
                                        onClick={() =>
                                            go({
                                                ...baseParams(),
                                                date: props.prev_week,
                                            })
                                        }
                                    >
                                        {t('tenant.book.prev_week')}
                                    </Button>
                                    <div className="grid flex-1 grid-cols-7 gap-1">
                                        {week.map((day) => (
                                            <button
                                                key={day.date}
                                                disabled={day.is_past}
                                                onClick={() =>
                                                    go({
                                                        ...baseParams(),
                                                        date: day.date,
                                                    })
                                                }
                                                className={`flex flex-col items-center rounded-lg border py-2 text-sm transition-colors ${
                                                    day.is_selected
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border hover:bg-accent disabled:opacity-30'
                                                }`}
                                            >
                                                <span className="text-xs">
                                                    {t(
                                                        `tenant.book.weekday.${day.weekday}`,
                                                    )}
                                                </span>
                                                <span className="font-semibold">
                                                    {day.day}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            go({
                                                ...baseParams(),
                                                date: props.next_week,
                                            })
                                        }
                                    >
                                        {t('tenant.book.next_week')}
                                    </Button>
                                </div>

                                <p className="text-xs text-muted-foreground">
                                    {t('tenant.book.timezone_note', {
                                        tz: timezone,
                                    })}
                                </p>

                                {/* Slots */}
                                {navigating ? (
                                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                        {Array.from({ length: 12 }).map(
                                            (_, i) => (
                                                <Skeleton
                                                    key={i}
                                                    className="h-10"
                                                />
                                            ),
                                        )}
                                    </div>
                                ) : slots.length === 0 ? (
                                    <p className="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                                        {t('tenant.book.no_slots')}
                                    </p>
                                ) : (
                                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                        {slots.map((slot) => (
                                            <button
                                                key={`${slot.start}-${slot.staff_id ?? ''}-${slot.room_id ?? ''}`}
                                                onClick={() =>
                                                    go({
                                                        ...baseParams(),
                                                        staff:
                                                            slot.staff_id ??
                                                            baseParams().staff,
                                                        room:
                                                            slot.room_id ??
                                                            baseParams().room,
                                                        start: slot.start,
                                                    })
                                                }
                                                className={`rounded-lg border py-2.5 text-sm font-medium transition-colors ${
                                                    slot.start === selectedStart
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'border-border hover:bg-accent'
                                                }`}
                                            >
                                                {slot.time}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {/* Selected slot → hand-off to the booking flow (SLO-31) */}
                                {selectedSlot ? (
                                    <div className="flex flex-col items-start gap-3 rounded-xl border border-border bg-card p-5 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="text-sm">
                                            <div className="text-muted-foreground">
                                                {t('tenant.book.selected')}
                                            </div>
                                            <div className="font-semibold">
                                                {props.selected_date}
                                                {' · '}
                                                {selectedSlot.time}
                                            </div>
                                        </div>
                                        <Button disabled>
                                            {t('tenant.book.continue')}
                                            <span className="ml-1 text-xs opacity-70">
                                                {t('tenant.book.coming_soon')}
                                            </span>
                                        </Button>
                                    </div>
                                ) : null}
                            </>
                        ) : (
                            <p className="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                                {service.booking_mode === 'event_based'
                                    ? t('tenant.book.event_soon')
                                    : t('tenant.book.other_soon')}
                            </p>
                        )}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
