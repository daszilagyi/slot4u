import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import { ArrowLeftIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import PublicLayout from '@/Layouts/PublicLayout';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useTranslations } from '@/lib/i18n';
import type {
    BookDay,
    BookLocationOption,
    BookRoomOption,
    BookSlot,
    BookStaffOption,
    RescheduleBooking,
} from '@/types';

type RescheduleProps = {
    booking: RescheduleBooking;
    timezone: string;
    filters: {
        staff: number | null;
        room: number | null;
        location: number | null;
    };
    week: BookDay[];
    selected_date: string;
    prev_week: string;
    next_week: string;
    is_first_week: boolean;
    slot_interval_minutes: number;
    slots: BookSlot[];
    staff_options: BookStaffOption[];
    room_options: BookRoomOption[];
    location_options: BookLocationOption[];
};

/** The `start` query param (an ISO instant) marks the selected slot, if any. */
function selectedStartFrom(url: string): string | null {
    const query = url.split('?')[1] ?? '';
    return new URLSearchParams(query).get('start');
}

/**
 * The "HH:mm" wall-clock end time `minutes` after a "HH:mm" start, wrapping past
 * midnight (free-range rental preview, mirrors Book.tsx).
 */
function endTimeFrom(start: string, minutes: number): string {
    const [h, m] = start.split(':').map(Number);
    const total = (h * 60 + m + minutes) % (24 * 60);
    const eh = Math.floor(total / 60);
    const em = total % 60;
    return `${String(eh).padStart(2, '0')}:${String(em).padStart(2, '0')}`;
}

/**
 * Members-area online reschedule (SLO-97): a focused, standalone slot re-picker
 * for one of the customer's own time-slot bookings. Reuses the same navigation /
 * slot-grid / free-range duration pattern as the public Book.tsx slot section, but
 * posts to /my/bookings/{id}/reschedule. RescheduleBooking runs online (deadline
 * enforced): a within-deadline attempt comes back on the `cancel` key, a taken
 * slot on the `booking` key.
 */
export default function Reschedule(props: RescheduleProps) {
    const { booking, timezone } = props;
    const t = useTranslations();
    const page = usePage();
    const [navigating, setNavigating] = useState(false);

    const base = `/my/bookings/${booking.id}/reschedule`;
    const selectedStart = selectedStartFrom(page.url);
    const filters = props.filters;

    function go(params: Record<string, string | number | undefined>) {
        router.get(base, params, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setNavigating(true),
            onFinish: () => setNavigating(false),
        });
    }

    function baseParams(): Record<string, string | number | undefined> {
        return {
            location: filters.location ?? undefined,
            staff: filters.staff ?? undefined,
            room: filters.room ?? undefined,
            date: props.selected_date,
        };
    }

    const isDurationBased = booking.booking_mode === 'duration_based';
    const isResourceRental = booking.booking_mode === 'resource_rental';

    const locationOptions = props.location_options;
    const staffOptions = props.staff_options.filter(
        (s) =>
            filters.location === null ||
            s.location_ids.includes(filters.location),
    );
    const roomOptions = props.room_options.filter(
        (r) => filters.location === null || r.location_id === filters.location,
    );
    const slots = props.slots;
    const week = props.week;
    const selectedSlot = slots.find((s) => s.start === selectedStart) ?? null;

    // Free-range resource_rental: pick a length between the service's min and max,
    // stepped on the tenant's slot grid (mirrors Book.tsx).
    const isFreeRangeRental =
        booking.booking_mode === 'resource_rental' &&
        booking.duration_minutes === null;
    const slotInterval = props.slot_interval_minutes;
    const minDuration = booking.min_duration_minutes;
    const maxDuration = booking.max_duration_minutes;

    const durationOptions: number[] = [];
    if (
        isFreeRangeRental &&
        minDuration !== null &&
        maxDuration !== null &&
        maxDuration > minDuration
    ) {
        for (let d = minDuration; d < maxDuration; d += slotInterval) {
            durationOptions.push(d);
        }
        if (durationOptions[durationOptions.length - 1] !== maxDuration) {
            durationOptions.push(maxDuration);
        }
    }

    const slotLengthMinutes = selectedSlot
        ? Math.round(
              (Date.parse(selectedSlot.end) - Date.parse(selectedSlot.start)) /
                  60000,
          )
        : null;
    const [duration, setDuration] = useState<number | null>(null);
    const [durationSlotStart, setDurationSlotStart] = useState<string | null>(
        selectedSlot?.start ?? null,
    );
    let effectiveDuration = duration;
    if ((selectedSlot?.start ?? null) !== durationSlotStart) {
        setDurationSlotStart(selectedSlot?.start ?? null);
        setDuration(null);
        effectiveDuration = null;
    }
    const chosenDuration = isFreeRangeRental
        ? (effectiveDuration ?? minDuration ?? slotLengthMinutes ?? 0)
        : null;

    const form = useForm({});

    // A taken slot (SlotUnavailableException → withErrors(['booking'])), the
    // within-deadline refusal (CancelBooking → `cancel`), and the free-range
    // duration-bounds error all arrive as non-field errors on the shared bag.
    const errors = page.props.errors as Record<string, string> | undefined;
    const bookingError = errors?.booking;
    const cancelError = errors?.cancel;
    const durationError = errors?.duration_minutes;

    function submit() {
        if (!selectedSlot) {
            return;
        }
        form.transform(() => ({
            staff_id: selectedSlot.staff_id,
            room_id: selectedSlot.room_id,
            starts_at: selectedSlot.start,
            ends_at:
                isFreeRangeRental && chosenDuration
                    ? new Date(
                          Date.parse(selectedSlot.start) +
                              chosenDuration * 60000,
                      ).toISOString()
                    : selectedSlot.end,
            ...(isFreeRangeRental
                ? { duration_minutes: chosenDuration }
                : {}),
        }));
        // On success the server PRG-redirects to /my/bookings (Inertia follows);
        // surface the confirmation as a toast on the destination, like cancel.
        form.post(base, {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(t('tenant.my_reschedule.rescheduled')),
        });
    }

    return (
        <PublicLayout>
            <Head title={t('tenant.my_reschedule.title')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-10 sm:px-6">
                <Link
                    href="/my/bookings"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeftIcon className="size-4" />
                    {t('tenant.my_reschedule.back')}
                </Link>

                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('tenant.my_reschedule.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {booking.service}
                    </p>
                    {booking.current_local ? (
                        <p className="text-sm text-muted-foreground">
                            {t('tenant.my_reschedule.current')}:{' '}
                            <span className="font-medium text-foreground">
                                {booking.current_local}
                            </span>
                        </p>
                    ) : null}
                </div>

                {cancelError ? (
                    <p
                        role="alert"
                        className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    >
                        {cancelError}
                    </p>
                ) : null}

                <h2 className="text-sm font-semibold tracking-tight text-muted-foreground uppercase">
                    {t('tenant.my_reschedule.pick')}
                </h2>

                {/* Filters */}
                <div className="flex flex-wrap gap-3">
                    {locationOptions.length > 1 ? (
                        <label className="flex flex-col gap-1 text-sm">
                            <span className="text-muted-foreground">
                                {t('tenant.book.location_label')}
                            </span>
                            <select
                                value={filters.location ?? ''}
                                onChange={(e) =>
                                    go({
                                        ...baseParams(),
                                        location: e.target.value || undefined,
                                        staff: undefined,
                                        room: undefined,
                                    })
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                            >
                                <option value="">
                                    {t('tenant.book.all_locations')}
                                </option>
                                {locationOptions.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : null}

                    {isDurationBased && staffOptions.length > 0 ? (
                        <label className="flex flex-col gap-1 text-sm">
                            <span className="text-muted-foreground">
                                {t('tenant.book.staff_label')}
                            </span>
                            <select
                                value={filters.staff ?? ''}
                                onChange={(e) =>
                                    go({
                                        ...baseParams(),
                                        staff: e.target.value || undefined,
                                    })
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                            >
                                <option value="">
                                    {t('tenant.book.anyone')}
                                </option>
                                {staffOptions.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    ) : null}

                    {isResourceRental && roomOptions.length > 0 ? (
                        <label className="flex flex-col gap-1 text-sm">
                            <span className="text-muted-foreground">
                                {t('tenant.book.room_label')}
                            </span>
                            <select
                                value={filters.room ?? ''}
                                onChange={(e) =>
                                    go({
                                        ...baseParams(),
                                        room: e.target.value || undefined,
                                    })
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                            >
                                <option value="">
                                    {t('tenant.book.any_room')}
                                </option>
                                {roomOptions.map((r) => (
                                    <option key={r.id} value={r.id}>
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
                            go({ ...baseParams(), date: props.prev_week })
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
                                    go({ ...baseParams(), date: day.date })
                                }
                                className={`flex flex-col items-center rounded-lg border py-2 text-sm transition-colors ${
                                    day.is_selected
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border hover:bg-accent disabled:opacity-30'
                                }`}
                            >
                                <span className="text-xs">
                                    {t(`tenant.book.weekday.${day.weekday}`)}
                                </span>
                                <span className="font-semibold">{day.day}</span>
                            </button>
                        ))}
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            go({ ...baseParams(), date: props.next_week })
                        }
                    >
                        {t('tenant.book.next_week')}
                    </Button>
                </div>

                <p className="text-xs text-muted-foreground">
                    {t('tenant.book.timezone_note', { tz: timezone })}
                </p>

                {/* Slots */}
                {navigating ? (
                    <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
                        {Array.from({ length: 12 }).map((_, i) => (
                            <Skeleton key={i} className="h-10" />
                        ))}
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
                                            slot.staff_id ?? baseParams().staff,
                                        room: slot.room_id ?? baseParams().room,
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

                {/* Selected slot → confirm card */}
                {selectedSlot ? (
                    <motion.div
                        key={selectedSlot.start}
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.25 }}
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
                                {isFreeRangeRental && chosenDuration
                                    ? ` – ${endTimeFrom(selectedSlot.time, chosenDuration)}`
                                    : null}
                            </div>
                        </div>

                        {bookingError ? (
                            <p className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                {bookingError}
                            </p>
                        ) : null}

                        {isFreeRangeRental && durationOptions.length > 1 ? (
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="reschedule-duration">
                                    {t('tenant.my_reschedule.duration')}
                                </Label>
                                <select
                                    id="reschedule-duration"
                                    value={chosenDuration ?? ''}
                                    onChange={(e) =>
                                        setDuration(Number(e.target.value))
                                    }
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm"
                                >
                                    {durationOptions.map((opt) => (
                                        <option key={opt} value={opt}>
                                            {t('tenant.book.minutes', {
                                                count: String(opt),
                                            })}
                                        </option>
                                    ))}
                                </select>
                                {durationError ? (
                                    <p className="text-sm text-destructive">
                                        {durationError}
                                    </p>
                                ) : null}
                            </div>
                        ) : null}

                        <Button
                            type="button"
                            disabled={form.processing}
                            onClick={submit}
                            className="w-full sm:w-auto sm:self-end"
                        >
                            {t('tenant.my_reschedule.confirm')}
                        </Button>
                    </motion.div>
                ) : null}
            </div>
        </PublicLayout>
    );
}
