<?php

namespace App\Support;

/**
 * Builds the app's Content-Security-Policy header (SLO-145, docs/01).
 *
 * Kept out of the middleware so both branches are testable without standing up a
 * Vite dev server: the caller says whether the frontend is running hot, this only
 * turns that plus configuration into a policy string.
 *
 * Scripts are nonce-gated rather than `unsafe-inline`: the root Blade has exactly
 * one inline script (the pre-paint theme switch) and Laravel's Vite helper stamps
 * the same nonce on the tags it emits. Inertia serialises its props into a
 * `<script type="application/json">` data block, which is not executable and so is
 * not subject to script-src at all.
 *
 * Styles are the deliberate exception (`unsafe-inline`): Radix and the toast
 * library insert <style> elements at runtime, and a stylesheet injection is a far
 * smaller prize than script execution. Widening it is a conscious trade, recorded
 * here rather than discovered later from a broken page.
 */
final class ContentSecurityPolicy
{
    /**
     * @param  string|null  $nonce  The per-request script nonce.
     * @param  bool  $hot  Whether the Vite dev server is serving the frontend.
     * @param  string|null  $devServer  Origin of that dev server (e.g. http://localhost:5173).
     * @param  string|null  $websocket  Realtime origin the browser connects to (Reverb).
     * @param  array{script?: string, connect?: string, img?: string, frame?: string}  $extra
     */
    public function __construct(
        private readonly ?string $nonce = null,
        private readonly bool $hot = false,
        private readonly ?string $devServer = null,
        private readonly ?string $websocket = null,
        private readonly array $extra = [],
    ) {}

    public function build(): string
    {
        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $this->scriptSources()),
            'style-src '.implode(' ', $this->styleSources()),
            'img-src '.implode(' ', $this->sources(["'self'", 'data:', 'blob:'], 'img')),
            "font-src 'self' data:",
            'connect-src '.implode(' ', $this->connectSources()),
            // No plugins, no <base> rewriting, no posting the session somewhere else.
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            // The modern equivalent of X-Frame-Options; both are sent, because the
            // header still wins in some corporate proxies.
            'frame-ancestors '.($this->extra['frame'] ?? '' ? implode(' ', $this->list('frame')) : "'none'"),
        ];

        return implode('; ', $directives);
    }

    /**
     * @return list<string>
     */
    private function scriptSources(): array
    {
        $sources = ["'self'"];

        if ($this->nonce !== null && $this->nonce !== '') {
            $sources[] = "'nonce-".$this->nonce."'";
        }

        if ($this->hot) {
            // React Refresh compiles components with eval, and the HMR client is
            // served from the dev server's own origin. Neither is ever true of a
            // built bundle, so this widening cannot reach production.
            $sources[] = "'unsafe-eval'";
            if ($this->devServer !== null) {
                $sources[] = $this->devServer;
            }
        }

        return array_merge($sources, $this->list('script'));
    }

    /**
     * @return list<string>
     */
    private function styleSources(): array
    {
        $sources = ["'self'", "'unsafe-inline'"];

        if ($this->hot && $this->devServer !== null) {
            $sources[] = $this->devServer;
        }

        return $sources;
    }

    /**
     * @return list<string>
     */
    private function connectSources(): array
    {
        $sources = ["'self'"];

        if ($this->websocket !== null) {
            $sources[] = $this->websocket;
        }

        if ($this->hot && $this->devServer !== null) {
            $sources[] = $this->devServer;
            // Vite's HMR channel is a websocket on the same host and port.
            $sources[] = str_replace(['http://', 'https://'], ['ws://', 'wss://'], $this->devServer);
        }

        return array_merge($sources, $this->list('connect'));
    }

    /**
     * @param  list<string>  $base
     * @return list<string>
     */
    private function sources(array $base, string $key): array
    {
        return array_merge($base, $this->list($key));
    }

    /**
     * Configured extra origins for a directive, comma-separated.
     *
     * @return list<string>
     */
    private function list(string $key): array
    {
        $raw = trim((string) ($this->extra[$key] ?? ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
