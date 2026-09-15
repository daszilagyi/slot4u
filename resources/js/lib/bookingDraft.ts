/**
 * What the booking wizard keeps while the guest is away at Google or Facebook
 * (SLO-252, docs/28).
 *
 * The service, date and chosen slot already live in the URL, which the social
 * flow brings back as its return path. What does not — the typed phone number,
 * the notes, the billing details, the picked event, the quote answers — is
 * React state, and a full-page round trip to a provider would lose it. So it
 * goes into `sessionStorage` right before leaving and is taken back (once) on
 * return: per tab, gone when the tab closes, never sent to the server.
 *
 * Name and e-mail are stored too, but on return the signed-in account (or the
 * provider's prefill) wins over them.
 */

const KEY = 'slot4u.booking-draft';

/** A draft older than this is a different visit, not a return. */
const MAX_AGE_MS = 30 * 60 * 1000;

export type BookingDraft = {
    path: string;
    form: Record<string, unknown>;
    eventForm: Record<string, unknown>;
    eventSelection: { id: number; mode: 'book' | 'waitlist' } | null;
    answers: Record<string, string>;
};

export function saveBookingDraft(draft: BookingDraft): void {
    try {
        window.sessionStorage.setItem(
            KEY,
            JSON.stringify({ ...draft, savedAt: Date.now() }),
        );
    } catch {
        // Storage blocked (private mode, quota): the return still works, the
        // guest just types the details again.
    }
}

/** The draft saved on `path`, removed as it is read. */
export function takeBookingDraft(path: string): BookingDraft | null {
    try {
        const raw = window.sessionStorage.getItem(KEY);
        window.sessionStorage.removeItem(KEY);

        if (!raw) {
            return null;
        }

        const draft = JSON.parse(raw) as BookingDraft & { savedAt: number };

        if (Date.now() - draft.savedAt > MAX_AGE_MS || draft.path !== path) {
            return null;
        }

        return draft;
    } catch {
        return null;
    }
}

/**
 * The fields a draft may put back: never the legal tick box (consent is given
 * on the page it is shown on) and never a field that already has a value.
 */
export function restorableFields<T extends Record<string, unknown>>(
    current: T,
    saved: Record<string, unknown>,
): Partial<T> {
    const restored: Partial<T> = {};

    for (const key of Object.keys(current) as (keyof T & string)[]) {
        if (key === 'accepted_legal' || key === 'legal_document_ids') {
            continue;
        }

        const now = current[key];
        const before = saved[key];

        if (
            before !== undefined &&
            typeof before === typeof now &&
            (now === '' || now === false || now === null)
        ) {
            restored[key] = before as T[keyof T & string];
        }
    }

    return restored;
}
