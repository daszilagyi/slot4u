import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

import { playChime } from '@/lib/chime';
import { getEcho } from '@/lib/echo';
import { useTranslations } from '@/lib/i18n';

/** The `booking.created` broadcast payload (server: BookingCreated::broadcastWith). */
export type LiveBooking = {
    id: number;
    code: string;
    customer_name: string | null;
    service_name: string | null;
    starts_at: string | null;
    status: string;
    source: string;
};

/**
 * Subscribe the current admin to their tenant's live booking feed (SLO-118): every
 * new booking pops a toast and plays a chime. Listens on the private per-tenant
 * channel authorized in TenantBookingChannel (staff-only), and leaves it on
 * unmount / tenant change. A no-op when there is no tenant or Echo is unavailable
 * (SSR, unconfigured), so it is safe to call unconditionally.
 *
 * `onBooking` lets a page react beyond the toast — the dashboard (SLO-43) uses it
 * to re-pull its own numbers, so the tiles stay live. Like the translator it is
 * held in a ref, so passing a fresh closure every render never re-opens the socket.
 */
export function useLiveBookings(
    tenantId: number | undefined,
    onBooking?: (booking: LiveBooking) => void,
): void {
    const t = useTranslations();
    // Keep the latest translator without making it a subscription dependency —
    // otherwise every render (t is re-created) would tear down and re-open the
    // WebSocket channel.
    const tRef = useRef(t);
    const onBookingRef = useRef(onBooking);
    useEffect(() => {
        tRef.current = t;
        onBookingRef.current = onBooking;
    });

    useEffect(() => {
        if (tenantId === undefined) {
            return;
        }

        const echo = getEcho();
        if (echo === null) {
            return;
        }

        const channelName = `tenant.${tenantId}.bookings`;

        echo.private(channelName).listen('.booking.created', (booking: LiveBooking) => {
            const tr = tRef.current;
            toast.success(tr('admin.dashboard.live.title'), {
                description: tr('admin.dashboard.live.body', {
                    service: booking.service_name ?? tr('admin.dashboard.live.unknown_service'),
                    customer: booking.customer_name ?? tr('admin.dashboard.live.unknown_customer'),
                }),
            });
            playChime();
            onBookingRef.current?.(booking);
        });

        return () => {
            echo.leave(channelName);
        };
    }, [tenantId]);
}
