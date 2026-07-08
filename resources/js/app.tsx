import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot, hydrateRoot } from 'react-dom/client';

import AppProviders from '@/components/AppProviders';
import ClientOnly from '@/components/ClientOnly';
import { Toaster } from '@/components/ui/sonner';

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
