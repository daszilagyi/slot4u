import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import ReactDOMServer from 'react-dom/server';

createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} · slot4u` : 'slot4u'),
        resolve: (name) =>
            resolvePageComponent<ResolvedComponent>(
                `./Pages/${name}.tsx`,
                import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx'),
            ),
        setup: ({ App, props }) => <App {...props} />,
    }),
);
