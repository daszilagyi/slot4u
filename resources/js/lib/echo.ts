import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let echo: Echo<'reverb'> | null = null;

/**
 * The shared Laravel Echo client, talking to Reverb over WebSockets (SLO-118).
 * Created lazily on first use and only in the browser — it is never touched
 * during SSR (callers subscribe from effects, which don't run on the server), so
 * the WebSocket connection opens once, when an admin page actually needs it.
 * Returns null when there is no window (SSR) or no configured key.
 */
export function getEcho(): Echo<'reverb'> | null {
    if (typeof window === 'undefined') {
        return null;
    }

    if (echo !== null) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        return null;
    }

    window.Pusher = Pusher;

    const port = Number(import.meta.env.VITE_REVERB_PORT || '8080');
    const forceTLS = (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https';

    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS,
        enabledTransports: ['ws', 'wss'],
    });

    return echo;
}
