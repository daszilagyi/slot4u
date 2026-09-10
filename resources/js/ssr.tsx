import type { Page } from '@inertiajs/core';
import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import {
    createServer,
    type IncomingMessage,
    type ServerResponse,
} from 'node:http';
import ReactDOMServer from 'react-dom/server';

import AppProviders from '@/components/AppProviders';

/**
 * The server-side renderer (SLO-212).
 *
 * ⚠️ This used to be `createServer` from `@inertiajs/react/server`, and the
 * reason it no longer is comes from production: on the shared host the renderer
 * runs under Phusion Passenger, mounted at `/_ssr`, and **Passenger does not
 * strip the base URI**. The app receives `/_ssr/render`. Inertia's own server
 * dispatches with an exact string lookup —
 *
 *     const dispatchRoute = routes[request.url] ?? routes['/404']
 *
 * — and takes no base-path option, so every request under the mount answered
 * `NOT_FOUND` while looking perfectly healthy from the outside.
 *
 * So the dispatch here is on the LAST PATH SEGMENT and therefore
 * prefix-agnostic: `/render`, `/_ssr/render` and any future mount all work, and
 * nothing has to be told where it lives. The same bundle serves the docker
 * `ssr` service and CI unchanged, both of which call it without a prefix.
 *
 * What was given up with Inertia's server: its source-mapped error
 * classification. {@see describeFailure} recovers the part that actually earned
 * its keep — naming the browser API a component reached for — because that is
 * the failure this project will hit, and a bare stack trace from a bundled file
 * is close to unreadable.
 */

const PORT = Number(process.env.SSR_PORT ?? 13714);
const HOST = process.env.SSR_HOST ?? '0.0.0.0';

/**
 * The shared secret the caller must present on `/render`.
 *
 * ⚠️ Unset means unauthenticated, which is what dev and CI need and what
 * production must not be left as. It cannot refuse to start instead: Inertia
 * falls back to client-side rendering when the renderer is unreachable, so a
 * renderer that refused to boot would look exactly like the bug this whole
 * issue is about — pages quietly shipping empty. It shouts instead, and the
 * deploy smoke test is what makes it non-optional.
 */
const SECRET = process.env.SSR_SHARED_SECRET ?? '';

/** Browser-only globals a component may reach for during a server render. */
const BROWSER_APIS = [
    'window',
    'document',
    'navigator',
    'location',
    'localStorage',
    'sessionStorage',
    'matchMedia',
    'requestAnimationFrame',
];

function describeFailure(error: Error, component: string): string {
    const reached = BROWSER_APIS.find((api) =>
        new RegExp(`\\b${api}\\b`).test(error.message),
    );

    if (error instanceof ReferenceError && reached !== undefined) {
        return (
            `[ssr] ${component} touched \`${reached}\` while rendering on the server, ` +
            'where it does not exist. Move it into an effect, or guard it with ' +
            `\`typeof ${reached} !== 'undefined'\`.\n${error.stack}`
        );
    }

    return `[ssr] ${component} failed to render.\n${error.stack}`;
}

function body(request: IncomingMessage): Promise<string> {
    return new Promise((resolve, reject) => {
        let data = '';
        request.on('data', (chunk) => (data += chunk));
        request.on('end', () => resolve(data));
        request.on('error', reject);
    });
}

/** The last path segment, which is what the route is, wherever it is mounted. */
function action(url: string | undefined): string {
    const path = (url ?? '/').split('?')[0].replace(/\/+$/, '');

    return path.slice(path.lastIndexOf('/') + 1);
}

function json(
    response: ServerResponse<IncomingMessage>,
    status: number,
    payload: unknown,
): void {
    response.writeHead(status, {
        'Content-Type': 'application/json',
        Server: 'Inertia.js SSR',
    });
    response.end(JSON.stringify(payload));
}

function render(page: Page) {
    return createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} · slot4u` : 'slot4u'),
        resolve: (name) =>
            resolvePageComponent<ResolvedComponent>(
                `./Pages/${name}.tsx`,
                import.meta.glob<ResolvedComponent>('./Pages/**/*.tsx'),
            ),
        setup: ({ App, props }) => (
            <AppProviders>
                <App {...props} />
            </AppProviders>
        ),
    });
}

/**
 * ⚠️ One try/catch around the whole handler, and it is not defensive habit.
 *
 * An async request handler that throws produces an unhandled rejection, and
 * Node exits on those. A single POST whose body is not JSON therefore killed
 * the entire renderer — a one-line denial of service on an endpoint reachable
 * from the internet. Found by accident while testing this file, by sending it a
 * body that was not JSON: the process was simply gone.
 */
createServer(async (request, response) => {
    try {
        await handle(request, response);
    } catch (error) {
        console.error('[ssr] request failed', error);

        if (!response.headersSent) {
            json(response, 500, { status: 'ERROR', timestamp: Date.now() });
        }
    }
}).listen({ port: PORT, host: HOST }, () => {
    if (process.env.NODE_ENV === 'production' && SECRET === '') {
        console.warn(
            '[ssr] ⚠️  SSR_SHARED_SECRET is not set: /render is open to anyone ' +
                'who can reach this mount. Set it on the Node application and ' +
                'in the app .env (SSR_SHARED_SECRET) so the two match.',
        );
    }

    console.log(`Inertia SSR server listening on ${HOST}:${PORT}`);
});

async function handle(
    request: IncomingMessage,
    response: ServerResponse<IncomingMessage>,
): Promise<void> {
    switch (action(request.url)) {
        // Deliberately unauthenticated: it discloses nothing, and a health
        // check that needs a credential is a health check nobody runs.
        case 'health':
            return json(response, 200, { status: 'OK', timestamp: Date.now() });

        case 'render':
            return handleRender(request, response);

        default:
            return json(response, 404, {
                status: 'NOT_FOUND',
                timestamp: Date.now(),
            });
    }
}

async function handleRender(
    request: IncomingMessage,
    response: ServerResponse<IncomingMessage>,
): Promise<void> {
    if (SECRET !== '' && request.headers['x-ssr-secret'] !== SECRET) {
        // 404, not 401: an unauthorised caller learns only that there is
        // nothing here, which is what a scanner that found `/_ssr` should
        // conclude.
        return json(response, 404, {
            status: 'NOT_FOUND',
            timestamp: Date.now(),
        });
    }

    let page: Page;

    try {
        page = JSON.parse(await body(request)) as Page;
    } catch {
        // 400 rather than a crash. Nothing legitimate sends this, so it is not
        // worth a log line per attempt.
        return json(response, 400, {
            status: 'BAD_REQUEST',
            timestamp: Date.now(),
        });
    }

    try {
        return json(response, 200, await render(page));
    } catch (error) {
        // Logged where the operator will look (Passenger's app log), and
        // answered with a 500 so Laravel's gateway records a failure rather
        // than treating an empty page as a successful render.
        console.error(describeFailure(error as Error, page.component));

        return json(response, 500, {
            status: 'ERROR',
            component: page.component,
            timestamp: Date.now(),
        });
    }
}
