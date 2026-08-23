import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';

import AppProviders from '@/components/AppProviders';
import ClientOnly from '@/components/ClientOnly';
import { Toaster } from '@/components/ui/sonner';
import { startAnalytics } from '@/lib/analytics';
import { startErrorReporting } from '@/lib/monitoring';

// Before the app renders, so a crash during the very first render is reported
// too. No-op without a DSN (SLO-153); the browser entry only — the SSR bundle
// runs on the server, where the Laravel SDK is already watching.
startErrorReporting();

// Page views for the measurement tag, if the server emitted one (SLO-172).
// A no-op when it did not — which is every page a visitor who declined
// analytics sees, every tenant subdomain, and all of dev and CI.
startAnalytics();

createInertiaApp({
    title: (title) => (title ? `${title} · slot4u` : 'slot4u'),
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./Pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const tree = (
            <AppProviders>
                <App {...props} />
                <ClientOnly>
                    <Toaster />
                </ClientOnly>
            </AppProviders>
        );

        // SSR-rendered markup present → hydrate it; empty shell (SSR disabled or
        // fell back) → fresh client render. Keeps both paths mismatch-free.
        if (el.hasChildNodes()) {
            hydrateRoot(el, tree);
        } else {
            createRoot(el).render(tree);
        }
    },
    progress: {
        color: '#6D5DF5',
    },
});
