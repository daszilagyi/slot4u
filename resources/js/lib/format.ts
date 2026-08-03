/** Format an ISO-8601 timestamp as a Hungarian short date, or em dash if null. */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('hu-HU', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
}

/** Format an ISO-8601 timestamp as a Hungarian short date+time, or em dash if null. */
export function formatDateTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString('hu-HU', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Format an integer minor-unit amount (e.g. fillér) as localized currency. */
export function formatMoney(minor: number, currency = 'HUF'): string {
    return new Intl.NumberFormat('hu-HU', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(minor / 100);
}

/** Format integer basis points as a percentage (100 bps → "1%", 150 → "1,5%"). */
export function formatRate(bps: number): string {
    return new Intl.NumberFormat('hu-HU', {
        style: 'percent',
        maximumFractionDigits: 2,
    }).format(bps / 10000);
}

/** Tailwind classes for a commission invoice / billing period status badge. */
export function billingStatusBadgeClass(status: string): string {
    const map: Record<string, string> = {
        open: 'bg-blue-500/15 text-blue-400',
        draft: 'bg-muted text-muted-foreground',
        issued: 'bg-blue-500/15 text-blue-400',
        invoiced: 'bg-blue-500/15 text-blue-400',
        paid: 'bg-green-500/15 text-green-400',
        overdue: 'bg-red-500/15 text-red-400',
        void: 'bg-muted text-muted-foreground',
    };

    return map[status] ?? 'bg-muted text-muted-foreground';
}

/**
 * Tailwind classes for a booking status (SLO-44). Grouped by what the status means
 * for the day rather than by lifecycle order: green = the slot is settled, amber =
 * it needs someone to act, grey = it is over.
 */
export function bookingStatusClass(status: string): string {
    const map: Record<string, string> = {
        requested: 'bg-amber-500/15 text-amber-300 border-amber-500/40',
        approved: 'bg-blue-500/15 text-blue-300 border-blue-500/40',
        pending_payment: 'bg-amber-500/15 text-amber-300 border-amber-500/40',
        confirmed: 'bg-primary/15 text-primary border-primary/40',
        completed: 'bg-green-500/15 text-green-300 border-green-500/40',
        no_show: 'bg-red-500/15 text-red-300 border-red-500/40',
        canceled: 'bg-muted text-muted-foreground border-border',
        rejected: 'bg-muted text-muted-foreground border-border',
    };

    return map[status] ?? 'bg-muted text-muted-foreground border-border';
}

/** Tailwind classes for a tenant status badge. */
export function statusBadgeClass(status: string): string {
    const map: Record<string, string> = {
        trial: 'bg-blue-500/15 text-blue-400',
        active: 'bg-green-500/15 text-green-400',
        suspended: 'bg-amber-500/15 text-amber-400',
        archived: 'bg-muted text-muted-foreground',
    };

    return map[status] ?? 'bg-muted text-muted-foreground';
}
