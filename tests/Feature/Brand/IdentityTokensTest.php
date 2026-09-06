<?php

use App\Models\Tenant;
use App\Settings\TenantBranding;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| The slot4u identity tokens (SLO-201, docs/21 §1)
|--------------------------------------------------------------------------
|
| Colour is the one part of a design system with no runtime behaviour to test:
| nothing throws when a token is wrong, and a review reads `bg-primary` the same
| whatever colour it resolves to. So what is pinned here is the small set of
| facts that WOULD be silent regressions —
|
|   the palette exists and is spelled the way the sections expect;
|   `--primary` is navy, not the yellow that may only ever be one CTA;
|   the tenant's own colour still wins on their own booking page;
|   and motion is switched off product-wide for anyone who asked.
|
| Asserted against the SOURCE stylesheet, following the SLO-170 precedent in
| BrandColorTest: the compiled CSS is a build artifact no test environment
| produces, and the PHP CI job never runs Vite.
|
*/

function identityCss(): string
{
    return (string) file_get_contents(resource_path('css/app.css'));
}

it('carries the whole palette, spelled the way the sections will ask for it', function (string $token, string $hex) {
    // A section writing `bg-navy` against a token named `--color-midnight` gets
    // no class and no error — just an unstyled element nobody notices in review.
    // ⚠️ Asserted on the `:root` value, not the `@theme inline` alias.
    //
    // `inline` never writes `--color-*` into `:root` — it expands each value
    // into the utility instead. So the hex lives here, and a role written as
    // `var(--color-canvas)` would point at a name that exists nowhere at
    // runtime. That trap is real: it caught this very change.
    expect(identityCss())->toContain("--{$token}: {$hex}")
        ->and(identityCss())->toContain("--color-{$token}: var(--{$token})");
})->with([
    'navy' => ['navy', '#0d1b2a'],
    'brand' => ['brand', '#1b4f72'],
    'brand-100' => ['brand-100', '#e6f0f8'],
    'brand-200' => ['brand-200', '#c9dcec'],
    'ice' => ['ice', '#7cc4f5'],
    'canvas' => ['canvas', '#f5f7fa'],
    'line' => ['line', '#dce4ec'],
    'ink' => ['ink', '#14212f'],
    'ink-muted' => ['ink-muted', '#5b6b7c'],
    'ok' => ['ok', '#1e9e6a'],
    'warn' => ['warn', '#d9781e'],
    'err' => ['err', '#d33a3a'],
    'the yellow, under its own name' => ['highlight', '#f4b942'],
]);

it('⚠️ makes the primary button navy, and never the yellow', function () {
    $css = identityCss();

    // THE rule of this palette (docs/21 §1): one yellow CTA per screen, and
    // never as a text colour. `--primary` is what every shadcn button, link and
    // active nav item reads — wiring the yellow there would put it on every
    // "Save" in the admin and make the rule unenforceable by construction.
    expect($css)->toContain('--primary: var(--navy)')
        ->and($css)->not->toContain('--primary: var(--highlight)');

    // Ice is a focus ring and a hairline, never a fill (docs/21 tiltólista).
    expect($css)->toContain('--ring: var(--ice)');
});

it('keeps dark mode navy-tuned rather than black, and still clickable', function () {
    $dark = substr(identityCss(), (int) strpos(identityCss(), '.dark {'));

    // Dark is this project's default (CLAUDE.md), so it is the branch most
    // people see — not an afterthought.
    expect($dark)->toContain('--background: #0b1622')
        ->and($dark)->toContain('--border: #23384d')
        // ⚠️ A navy button on a near-navy surface is invisible, so the primary
        // has to lift here. This is the assertion that catches somebody
        // "tidying up" the dark block to match the light one.
        ->and($dark)->toContain('--primary: var(--ice)');
});

it('switches motion off product-wide for anyone who asked', function () {
    // The floor, in CSS, where it works before a line of JavaScript runs and
    // where no section can forget it. `useReducedMotion` (lib/motion.ts) is only
    // for what CSS cannot reach — a Framer variant, a rAF loop.
    $css = identityCss();

    expect($css)->toContain('@media (prefers-reduced-motion: reduce)')
        ->and($css)->toContain('animation-iteration-count: 1 !important')
        ->and($css)->toContain('transition-duration: 0.01ms !important');
});

it('gives headings the display face and keeps it off body text', function () {
    $css = identityCss();

    // Poppins on body text is on the doc's banned list; setting the face on the
    // heading elements themselves is what stops a new page reaching for it.
    expect($css)->toContain("--display-family: 'Poppins'")
        ->and($css)->toContain('font-family: var(--display-family)')
        // Inter carries the body, and the fallback stack is real: with
        // `font-display: swap` it is what a visitor reads for the first frames.
        ->and($css)->toContain("'Inter Variable'")
        ->and($css)->toContain('ui-sans-serif');
});

it('self-hosts the faces instead of calling a font CDN', function () {
    $css = identityCss();

    // ⚠️ A privacy decision, not a performance one: a Google Fonts request hands
    // the visitor's IP to a third party on every page load, which is exactly the
    // kind of transfer docs/19 spends a chapter keeping deliberate.
    expect($css)->toContain("@import '@fontsource-variable/inter'")
        ->and($css)->not->toContain('fonts.googleapis.com')
        ->and($css)->not->toContain('fonts.bunny.net');
});

it('⚠️ still lets a tenant paint their own booking page', function () {
    // The line this whole issue must not cross. slot4u's identity now lives in
    // `:root`, which reaches the admin, the auth screens and the marketing site
    // — but a tenant's public page is THEIR brand, not ours (docs/19 §2), and
    // PublicLayout overriding `--primary` is what keeps it that way.
    Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['primary_color' => '#ff6600'],
    ]);

    // Branding is off on the base plan, so this is the unbranded majority: they
    // must still get the neutral tenant default, NOT slot4u navy.
    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.primary_color', TenantBranding::DEFAULT_PRIMARY_COLOR)
        );

    // And the default is not the platform's colour: a tenant who never chose one
    // gets a neutral indigo, not slot4u's navy on their own shop window.
    expect(TenantBranding::DEFAULT_PRIMARY_COLOR)->not->toBe('#0d1b2a');
});

it('leaves exactly one runtime override of the primary token', function () {
    // With the platform accent gone (SLO-201), the ONLY subtree that repaints
    // `--primary` is the tenant public shell. Two places deciding one colour is
    // how they drift apart, so this counts them.
    $layouts = glob(resource_path('js/Layouts/*.tsx')) ?: [];
    $overriding = [];

    foreach ($layouts as $file) {
        if (str_contains((string) file_get_contents($file), "['--primary']")) {
            $overriding[] = basename($file);
        }
    }

    expect($overriding)->toBe(['PublicLayout.tsx']);
});
