import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import AppProviders from '@/components/AppProviders';
import { Toaster } from '@/components/ui/sonner';

createInertiaApp({
    title: (title) => (title ? `${title} · slot4u` : 'slot4u'),
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./Pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <AppProviders>
                <App {...props} />
                <Toaster />
            </AppProviders>,
        );
    },
    progress: {
        color: '#6D5DF5',
    },
});
