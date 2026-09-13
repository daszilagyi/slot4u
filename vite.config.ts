import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    // ⚠️ The SSR bundle has to carry its dependencies (SLO-212). Vite leaves
    // them as bare imports by default — `react`, `framer-motion`, `pusher-js`
    // — resolved from node_modules at run time, and in production there are
    // none: the renderer runs from its own directory under Passenger, which
    // holds the bundle and nothing else. A bundle with a bare import simply
    // fails to boot there.
    //
    // The cost is a directory of ~1 MB instead of a file, and none of the
    // ~300 MB of packages that would otherwise have to be installed on the
    // server. `node:` builtins stay external, as they must.
    ssr: {
        noExternal: true,
    },
    server: {
        host: '0.0.0.0',
        cors: true,
        hmr: {
            host: 'localhost',
        },
        // ⚠️ The dev server watches the whole project root, and PHP writes into
        // it all the time: a test run puts thousands of files under storage/.
        // Watching them cost ~1.3 cores for as long as the writes lasted —
        // 176 298 inotify watches, down to 1 324 with this list (SLO-225,
        // measured under the same write load: 127–134% CPU → 0.2–0.4%).
        // Nothing here is a Vite input: PHP state, PHP dependencies, and the
        // build output Vite itself writes.
        watch: {
            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/public/build/**',
                '**/bootstrap/ssr/**',
                '**/.playwright-mcp/**',
            ],
        },
    },
});
