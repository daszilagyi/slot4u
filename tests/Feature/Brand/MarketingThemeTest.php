<?php

/**
 * The marketing shell is pinned to the light palette (SLO-208).
 *
 * ⚠️ Why this is a test and not just a class name someone can see.
 *
 * `.dark` is the default on `<html>`, and it overrides only the shadcn role
 * tokens — never the brand tokens the landing is built from. So the landing in
 * the default theme was ink #14212F on card #122234: a contrast ratio of 1.01,
 * which is not "hard to read", it is nothing at all. Nobody caught it in review
 * because the markup was correct; it only appeared in a browser.
 *
 * Two things have to stay true, and each is one careless edit away from being
 * false again: the palette must remain reachable from inside a dark document,
 * and the marketing shell must actually reach for it.
 */
$paletteSelector = function (): string {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // ⚠️ Comments first, and this is not fussiness: the first version of this
    // test searched the raw file and passed against the doc-comment right above
    // the block, which happens to name the class. It stayed green with the fix
    // removed — a test that proved nothing. Prose is not a selector.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    // The block carrying the palette's own hex values, found by a declaration
    // that exists nowhere else: `--color-ink:` in @theme inline would match a
    // bare `--ink:` search, so anchor on the value too.
    $start = strpos($css, '--ink: #');
    expect($start)->not->toBeFalse();

    $open = strrpos(substr($css, 0, $start), '{');
    $prev = strrpos(substr($css, 0, $open), '}');

    // Just the selector list in front of that block.
    return trim(substr($css, $prev === false ? 0 : $prev + 1, $open - ($prev === false ? 0 : $prev + 1)));
};

it('⚠️ keeps the light palette reachable from inside a dark document', function () use ($paletteSelector) {
    // The selector list in front of the palette must still carry `.theme-light`.
    // Drop it — "tidying up" a selector that looks redundant is exactly how this
    // would regress — and every brand token falls back to its dark-mode value,
    // which for the brand tokens means the light one, on a dark surface.
    expect($paletteSelector())->toContain('.theme-light')
        ->and($paletteSelector())->toContain(':root');
});

it('⚠️ scopes the marketing shell to that palette', function () {
    $layout = (string) file_get_contents(resource_path('js/Layouts/MarketingLayout.tsx'));

    // The class has to be on the shell's own root element. Anywhere deeper and
    // the header and footer — the navy bands — fall outside it.
    expect($layout)->toMatch('/className="theme-light[^"]*flex min-h-screen/');
});

it('offers no theme toggle on a surface that has one theme', function () {
    $layout = (string) file_get_contents(resource_path('js/Layouts/MarketingLayout.tsx'));

    // A switch that visibly does nothing is worse than no switch: it reads as a
    // broken control rather than a deliberate choice. The app's own surfaces
    // (PublicLayout, AdminLayout) keep theirs.
    expect($layout)->not->toContain('<ThemeToggle');
});

it('⚠️ leaves the application surfaces free to go dark', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    // The other half of the bargain. This change pins ONE shell; it must not
    // have quietly removed dark mode from the product, which is where the
    // shadcn role tokens do the work.
    expect($css)->toContain('.dark {')
        ->and($css)->toMatch('/\.dark \{[^}]*--card:/');

    foreach (['PublicLayout', 'AdminLayout'] as $layout) {
        expect((string) file_get_contents(resource_path("js/Layouts/{$layout}.tsx")))
            ->not->toContain('theme-light');
    }
});
