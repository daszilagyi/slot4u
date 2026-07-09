import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeftIcon, ClockIcon } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/format';
import { useTranslations } from '@/lib/i18n';
import type {
    BookDay,
    BookEvent,
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
    events?: BookEvent[];
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
    const isEventMode = service?.booking_mode === 'event_based';
    const isOrderMode = service?.booking_mode === 'no_time_slot';
    const events = props.events ?? [];

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

    // Booking details form (prefilled for a logged-in customer). The slot fields
    // are injected from the selected slot at submit time.
    const authUser = page.props.auth.user;
    const form = useForm({
        name: authUser?.name ?? '',
        email: authUser?.email ?? '',
        phone: '',
        notes: '',
    });

    // Event sign-up (SLO-100): selecting an event's "join" / "waitlist" CTA opens
    // its own details form, posted to the matching endpoint.
    const [eventSelection, setEventSelection] = useState<{
        id: number;
        mode: 'book' | 'waitlist';
    } | null>(null);
    const eventForm = useForm({
        party_size: 1,
        name: authUser?.name ?? '',
        email: authUser?.email ?? '',
        phone: '',
    });
    const selectedEvent =
        events.find((e) => e.id === eventSelection?.id) ?? null;

    function chooseEvent(id: number, mode: 'book' | 'waitlist') {
        setEventSelection({ id, mode });
        eventForm.clearErrors();
    }

    function submitEvent(e: FormEvent) {
        e.preventDefault();
        if (!eventSelection) return;
        const action = eventSelection.mode === 'waitlist' ? 'waitlist' : 'book';
        eventForm.post(`/events/${eventSelection.id}/${action}`, {
            preserveScroll: true,
        });
    }

    // The slot-taken error (SlotUnavailableException → withErrors(['booking'])) is a
    // non-field error, so it comes through the shared errors bag, not form.errors.
    const bookingError = (
        page.props.errors as Record<string, string> | undefined
    )?.booking;
    // Likewise the waitlist join errors (not_full / already_listed, JoinWaitlist)
    // arrive on the shared `waitlist` key, not eventForm.errors.
    const eventError = (page.props.errors as Record<string, string> | undefined)
        ?.waitlist;

    function submitBooking(event: FormEvent) {
        event.preventDefault();
        if (!service || !selectedSlot) {
            return;
        }
        form.transform((data) => ({
            ...data,
            service_id: service.id,
            staff_id: selectedSlot.staff_id,
            room_id: selectedSlot.room_id,
            starts_at: selectedSlot.start,
            ends_at: selectedSlot.end,
        }));
        // On success the server PRG-redirects to /booked/{code} (Inertia follows).
        form.post('/book', { preserveScroll: true });
    }

    /**
     * The guest's contact fields, shared by the slot booking and the no_time_slot
     * order (both post the same `form`). `prefix` keeps the input ids unique.
     */
    function guestFields(prefix: string) {
        return (
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={`${prefix}-name`}>
                        {t('tenant.book.form.name')}
                    </Label>
                    <Input
                        id={`${prefix}-name`}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    {form.errors.name ? (
                        <p className="text-sm text-destructive">
                            {form.errors.name}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={`${prefix}-email`}>
                        {t('tenant.book.form.email')}
                    </Label>
                    <Input
                        id={`${prefix}-email`}
                        type="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                    {form.errors.email ? (
                        <p className="text-sm text-destructive">
                            {form.errors.email}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={`${prefix}-phone`}>
                        {t('tenant.book.form.phone')}
                    </Label>
                    <Input
                        id={`${prefix}-phone`}
                        value={form.data.phone}
                        onChange={(e) => form.setData('phone', e.target.value)}
                    />
                </div>
                <div className="flex flex-col gap-1.5">
                    <Label htmlFor={`${prefix}-notes`}>
                        {t('tenant.book.form.notes')}
                    </Label>
                    <Input
                        id={`${prefix}-notes`}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                </div>
            </div>
        );
    }

    // no_time_slot order (SLO-101): the same details form, minus any slot — the
    // service alone identifies what is being ordered.
    function submitOrder(event: FormEvent) {
        event.preventDefault();
        if (!service) {
            return;
        }
        form.transform((data) => ({ ...data, service_id: service.id }));
        form.post('/order', { preserveScroll: true });
    }

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

                                {/* Selected slot → the details card (the picked
                                    time becomes the confirm card's header, SLO-31) */}
                                {selectedSlot ? (
                                    <motion.form
                                        key={selectedSlot.start}
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ duration: 0.25 }}
                                        onSubmit={submitBooking}
                                        className="flex flex-col gap-4 rounded-xl border border-primary/40 bg-card p-5"
                                    >
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

                                        {bookingError ? (
                                            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                                {bookingError}
                                            </p>
                                        ) : null}

                                        {guestFields('book')}

                                        <Button
                                            type="submit"
                                            disabled={form.processing}
                                            className="w-full sm:w-auto sm:self-end"
                                        >
                                            {t('tenant.book.confirm')}
                                        </Button>
                                    </motion.form>
                                ) : null}
                            </>
                        ) : isEventMode ? (
                            <div className="flex flex-col gap-3">
                                <p className="text-xs text-muted-foreground">
                                    {t('tenant.book.timezone_note', {
                                        tz: timezone,
                                    })}
                                </p>

                                {events.length === 0 ? (
                                    <p className="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                                        {t('tenant.book.no_events')}
                                    </p>
                                ) : (
                                    events.map((ev) => (
                                        <div
                                            key={ev.id}
                                            className={`flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between ${
                                                eventSelection?.id === ev.id
                                                    ? 'border-primary'
                                                    : 'border-border'
                                            }`}
                                        >
                                            <div className="flex flex-col gap-1">
                                                <span className="font-semibold">
                                                    {ev.starts_local}
                                                    {' – '}
                                                    {ev.ends_time}
                                                </span>
                                                <span className="text-sm text-muted-foreground">
                                                    {[ev.staff, ev.room]
                                                        .filter(Boolean)
                                                        .join(' · ') || '—'}
                                                </span>
                                                <span
                                                    className={`text-sm font-medium ${
                                                        ev.is_full
                                                            ? 'text-destructive'
                                                            : 'text-primary'
                                                    }`}
                                                >
                                                    {ev.is_full
                                                        ? t(
                                                              'tenant.book.event_full',
                                                          )
                                                        : t(
                                                              'tenant.book.event_remaining',
                                                              {
                                                                  remaining:
                                                                      ev.remaining,
                                                                  capacity:
                                                                      ev.capacity,
                                                              },
                                                          )}
                                                </span>
                                            </div>

                                            <div className="shrink-0">
                                                {!ev.is_full ? (
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            chooseEvent(
                                                                ev.id,
                                                                'book',
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'tenant.book.event_join',
                                                        )}
                                                    </Button>
                                                ) : ev.waitlist_available ? (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            chooseEvent(
                                                                ev.id,
                                                                'waitlist',
                                                            )
                                                        }
                                                    >
                                                        {t(
                                                            'tenant.book.event_waitlist',
                                                        )}
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </div>
                                    ))
                                )}

                                {/* Selected event → sign-up / waitlist details */}
                                {selectedEvent && eventSelection ? (
                                    <motion.form
                                        key={`${eventSelection.id}-${eventSelection.mode}`}
                                        initial={{ opacity: 0, y: 8 }}
                                        animate={{ opacity: 1, y: 0 }}
                                        transition={{ duration: 0.25 }}
                                        onSubmit={submitEvent}
                                        className="flex flex-col gap-4 rounded-xl border border-primary/40 bg-card p-5"
                                    >
                                        <div className="text-sm">
                                            <div className="text-muted-foreground">
                                                {t('tenant.book.selected')}
                                            </div>
                                            <div className="font-semibold">
                                                {selectedEvent.starts_local}
                                            </div>
                                        </div>

                                        {eventError ? (
                                            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                                {eventError}
                                            </p>
                                        ) : null}

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="event-party">
                                                    {t(
                                                        'tenant.book.party_size',
                                                    )}
                                                </Label>
                                                <Input
                                                    id="event-party"
                                                    type="number"
                                                    min={1}
                                                    value={
                                                        eventForm.data
                                                            .party_size
                                                    }
                                                    onChange={(e) =>
                                                        eventForm.setData(
                                                            'party_size',
                                                            Number(
                                                                e.target.value,
                                                            ),
                                                        )
                                                    }
                                                />
                                                {eventForm.errors
                                                    .party_size ? (
                                                    <p className="text-sm text-destructive">
                                                        {
                                                            eventForm.errors
                                                                .party_size
                                                        }
                                                    </p>
                                                ) : null}
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="event-name">
                                                    {t('tenant.book.form.name')}
                                                </Label>
                                                <Input
                                                    id="event-name"
                                                    value={eventForm.data.name}
                                                    onChange={(e) =>
                                                        eventForm.setData(
                                                            'name',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                {eventForm.errors.name ? (
                                                    <p className="text-sm text-destructive">
                                                        {eventForm.errors.name}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="event-email">
                                                    {t(
                                                        'tenant.book.form.email',
                                                    )}
                                                </Label>
                                                <Input
                                                    id="event-email"
                                                    type="email"
                                                    value={eventForm.data.email}
                                                    onChange={(e) =>
                                                        eventForm.setData(
                                                            'email',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                {eventForm.errors.email ? (
                                                    <p className="text-sm text-destructive">
                                                        {eventForm.errors.email}
                                                    </p>
                                                ) : null}
                                            </div>
                                            <div className="flex flex-col gap-1.5">
                                                <Label htmlFor="event-phone">
                                                    {t(
                                                        'tenant.book.form.phone',
                                                    )}
                                                </Label>
                                                <Input
                                                    id="event-phone"
                                                    value={eventForm.data.phone}
                                                    onChange={(e) =>
                                                        eventForm.setData(
                                                            'phone',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>

                                        <Button
                                            type="submit"
                                            disabled={eventForm.processing}
                                            className="w-full sm:w-auto sm:self-end"
                                        >
                                            {eventSelection.mode === 'waitlist'
                                                ? t(
                                                      'tenant.book.waitlist_confirm',
                                                  )
                                                : t(
                                                      'tenant.book.event_confirm',
                                                  )}
                                        </Button>
                                    </motion.form>
                                ) : null}
                            </div>
                        ) : isOrderMode ? (
                            <motion.form
                                initial={{ opacity: 0, y: 8 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.25 }}
                                onSubmit={submitOrder}
                                className="flex flex-col gap-4 rounded-xl border border-primary/40 bg-card p-5"
                            >
                                <p className="text-sm text-muted-foreground">
                                    {t('tenant.book.order_intro')}
                                </p>

                                {service.fulfillment_type ? (
                                    <p className="rounded-md bg-muted px-3 py-2 text-sm">
                                        {t(
                                            `tenant.book.fulfillment.${service.fulfillment_type}`,
                                        )}
                                    </p>
                                ) : null}

                                {guestFields('order')}

                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                    className="w-full sm:w-auto sm:self-end"
                                >
                                    {t('tenant.book.order_confirm')}
                                </Button>
                            </motion.form>
                        ) : (
                            <p className="rounded-xl border border-border bg-card px-4 py-10 text-center text-sm text-muted-foreground">
                                {t('tenant.book.other_soon')}
                            </p>
                        )}
                    </>
                )}
            </div>
        </PublicLayout>
    );
}
