<?php

/*
|--------------------------------------------------------------------------
| The SSR bundle carries its own dependencies (SLO-212)
|--------------------------------------------------------------------------
|
| In production the renderer runs from its OWN directory under Passenger, which
| holds the bundle and nothing else — no node_modules, and nothing to install
| them with. A bundle that leaves `react` or `framer-motion` as a bare import
| resolves them from a node_modules that is not there and fails to boot.
|
| ⚠️ Nothing else in the suite can see this. The renderer's contract tests talk
| to a process CI starts from the repository root, where node_modules is right
| there — so the bundle they exercise is not the bundle production runs. That is
| how `ssr: { noExternal: true }` came to be missing from vite.config.ts while
| everything looked fine: the setting was proven in the spike and never
| committed, and the first thing to notice would have been a dead renderer on
| production.
|
*/

/** Node's own modules, which stay external because Node provides them. */
const NODE_BUILTINS = [
    'assert', 'async_hooks', 'buffer', 'child_process', 'cluster', 'console',
    'constants', 'crypto', 'dgram', 'diagnostics_channel', 'dns', 'domain',
    'events', 'fs', 'http', 'http2', 'https', 'inspector', 'module', 'net',
    'os', 'path', 'perf_hooks', 'process', 'punycode', 'querystring',
    'readline', 'repl', 'stream', 'string_decoder', 'sys', 'timers',
    'tls', 'trace_events', 'tty', 'url', 'util', 'v8', 'vm', 'wasi',
    'worker_threads', 'zlib',
];

/**
 * Every module specifier the built bundle imports, keyed by the file it is in.
 *
 * @return array<string, list<string>>
 */
function ssrBundleImports(): array
{
    $files = glob(base_path('bootstrap/ssr/*.js')) ?: [];
    $files = [...$files, ...(glob(base_path('bootstrap/ssr/assets/*.js')) ?: [])];

    $found = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // ⚠️ The character class is what keeps prose out. Minified output puts
        // everything on one line, so a plain search for `from"` also finds the
        // inside of string literals — a real one was `from", children: t("`.
        // A module specifier has no spaces and no punctuation beyond these.
        preg_match_all(
            '/(?:from|import|require)\s*\(?\s*["\']([A-Za-z0-9@._\/-]+)["\']/',
            $source,
            $matches,
        );

        $bare = array_values(array_unique(array_filter(
            $matches[1],
            fn (string $specifier) => ! str_starts_with($specifier, '.')
                && ! str_starts_with($specifier, '/')
                && ! in_array(
                    str_starts_with($specifier, 'node:')
                        ? explode('/', substr($specifier, 5))[0]
                        : explode('/', $specifier)[0],
                    NODE_BUILTINS,
                    true,
                ),
        )));

        if ($bare !== []) {
            $found[basename($file)] = $bare;
        }
    }

    return $found;
}

beforeEach(function () {
    if (! is_file(base_path('bootstrap/ssr/ssr.js'))) {
        $this->markTestSkipped('No SSR bundle built — run `npm run build`.');
    }
});

it('⚠️ leaves nothing for node_modules to resolve', function () {
    $bare = ssrBundleImports();

    expect($bare)->toBe([], 'The SSR bundle imports packages it does not carry, so it '
        ."cannot boot where production runs it:\n"
        .json_encode($bare, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

it('proves the check can see a bare import at all', function () {
    // The other test asserts an absence, and an absence is what a broken
    // detector reports too. This is the detector meeting the exact shape it has
    // to catch — `from"react"` as the minifier writes it.
    preg_match_all(
        '/(?:from|import|require)\s*\(?\s*["\']([A-Za-z0-9@._\/-]+)["\']/',
        'import{jsx}from"react/jsx-runtime";import x from"framer-motion";const s="hello, from a string"',
        $matches,
    );

    expect($matches[1])->toBe(['react/jsx-runtime', 'framer-motion']);
});
