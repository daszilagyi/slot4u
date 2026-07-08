import { useSyncExternalStore, type PropsWithChildren } from 'react';

const emptySubscribe = () => () => {};

/**
 * Renders its children only on the client, after hydration. useSyncExternalStore
 * yields the server snapshot (false) during SSR and the initial hydration pass —
 * so the server and client trees match — then the client snapshot (true),
 * mounting the children. Keeps client-only widgets (e.g. the Sonner <Toaster />)
 * out of the hydrated markup without triggering a hydration mismatch.
 */
export default function ClientOnly({ children }: PropsWithChildren) {
    const mounted = useSyncExternalStore(
        emptySubscribe,
        () => true,
        () => false,
    );

    return mounted ? <>{children}</> : null;
}
