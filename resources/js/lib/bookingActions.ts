import type {
    BookingAbilities,
    BookingModeValue,
    BookingStatusValue,
} from '@/types';

/**
 * Which quick actions a booking offers, in one place (SLO-136).
 *
 * The booking list (SLO-85) and the calendar card both show the same buttons and
 * post to the same endpoints, so the rule that decides *when* a button appears
 * lives here rather than in each page — otherwise the two surfaces drift and one
 * of them starts offering a state change the server refuses.
 *
 * These are affordances only: every endpoint re-checks the permission, the
 * ownership scope and the state machine (an illegal transition is a 422).
 */
export type BookingActionKey = 'approve' | 'complete' | 'no_show' | 'cancel';

/** The fields an action decision needs — both row DTOs carry them. */
export type ActionableBooking = {
    id: number;
    status: BookingStatusValue;
    booking_mode: BookingModeValue;
};

/** Statuses the state machine has no way out of (docs/04 §5). */
export const TERMINAL_BOOKING_STATUSES: BookingStatusValue[] = [
    'completed',
    'canceled',
    'rejected',
    'no_show',
];

/** Modes a reschedule can move (docs/04 §2) — the drag and the list sheet alike. */
export const RESCHEDULABLE_BOOKING_MODES: BookingModeValue[] = [
    'duration_based',
    'resource_rental',
];

export function isTerminalBooking(booking: ActionableBooking): boolean {
    return TERMINAL_BOOKING_STATUSES.includes(booking.status);
}

export function canRescheduleBooking(
    booking: ActionableBooking,
    can: BookingAbilities,
): boolean {
    return (
        can.edit &&
        booking.status === 'confirmed' &&
        RESCHEDULABLE_BOOKING_MODES.includes(booking.booking_mode)
    );
}

/**
 * The quick actions available on this booking, in the order they should be shown.
 *
 * - `approve`: only an approval-pending booking, and only with the feature on
 *   (docs/04 §5). Rejecting needs a reason, so it stays on its own surface.
 * - `complete`: fulfilling a `no_time_slot` order (docs/04 §1) — the other modes
 *   complete on their own timeline.
 * - `no_show`: a confirmed booking the customer never showed up for.
 * - `cancel`: anything the state machine can still cancel.
 */
export function bookingQuickActions(
    booking: ActionableBooking,
    can: BookingAbilities,
): BookingActionKey[] {
    const actions: BookingActionKey[] = [];

    if (can.approve && booking.status === 'requested') {
        actions.push('approve');
    }
    if (
        can.edit &&
        booking.status === 'confirmed' &&
        booking.booking_mode === 'no_time_slot'
    ) {
        actions.push('complete');
    }
    if (can.edit && booking.status === 'confirmed') {
        actions.push('no_show');
    }
    if (can.cancel && !isTerminalBooking(booking)) {
        actions.push('cancel');
    }

    return actions;
}

/** The endpoint an action posts to — the same one on every surface. */
export function bookingActionUrl(
    action: BookingActionKey,
    bookingId: number,
): string {
    const path = action === 'no_show' ? 'no-show' : action;

    return `/bookings/${bookingId}/${path}`;
}

/** Translation keys for an action's button label, confirmation and toast. */
export function bookingActionKeys(action: BookingActionKey): {
    label: string;
    confirm: string;
    toast: string;
} {
    return {
        label: `admin.bookings.action.${action}`,
        confirm: `admin.bookings.confirm.${action}`,
        toast: `admin.bookings.toast.${TOAST_SUFFIX[action]}`,
    };
}

/** The toast keys predate the action names, so they are mapped, not derived. */
const TOAST_SUFFIX: Record<BookingActionKey, string> = {
    approve: 'approved',
    complete: 'completed',
    no_show: 'no_show',
    cancel: 'canceled',
};
