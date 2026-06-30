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
