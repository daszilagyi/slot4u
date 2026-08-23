<?php

use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Brand assets (SLO-170)
|--------------------------------------------------------------------------
|
| Everything here fails silently in production, which is the only reason it is
| worth a test. A missing favicon is a blank square nobody reports; a relative
| og:image is a link preview that renders empty on every platform at once, and
| those platforms CACHE the failure — so the first person to paste the link
| decides how it looks for everybody else for a while.
|
*/

it('points at the brand icons from every page', function () {
    // In the root Blade, so this covers the admin panel and the booking flow
    // too — not just the page that happened to be tested.
    $this->get('http://'.config('tenancy.central_domain').'/')
        ->assertOk()
        ->assertSee('/img/favicon-32.png', escape: false)
        ->assertSee('/img/apple-touch-icon.png', escape: false);
});

it('ships the icon files the markup promises', function () {
    // The pair that goes wrong together: a deploy that forgets `public/img`
    // leaves the links above pointing at 404s, and nothing else notices.
    foreach ([
        'img/favicon-32.png',
        'img/apple-touch-icon.png',
        'img/icon-192.png',
        'img/icon-512.png',
        'img/og-image.png',
    ] as $file) {
        expect(is_file(public_path($file)))->toBeTrue("missing: {$file}")
            // Not merely present: a zero-byte or truncated PNG passes an
            // existence check and renders as nothing.
            ->and(filesize(public_path($file)))->toBeGreaterThan(1000);
    }
});

it('gives the link preview an ABSOLUTE image url', function () {
    // The failure this exists for: a root-relative `/img/og-image.png` resolves
    // against the CRAWLER's host, not ours, so every preview 404s — and the
    // platforms cache that.
    $this->get('http://'.config('tenancy.central_domain').'/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('og_image', rtrim((string) config('app.url'), '/').'/img/og-image.png')
        );
});

it('serves an og image of the size the meta tags claim', function () {
    // The width/height meta tags are a promise. A platform that finds a
    // differently shaped image crops it to its own ratio, and the wordmark is
    // the first thing to go.
    $size = getimagesize(public_path('img/og-image.png'));

    expect($size[0])->toBe(1200)->and($size[1])->toBe(630);
});

it('keeps the brand tile as a vector, not a wrapped bitmap', function () {
    // An SVG that only embeds a PNG scales no better than the PNG and costs
    // more. This is the check that would have caught the export going wrong.
    $svg = (string) file_get_contents(resource_path('images/brand-tile.svg'));

    expect($svg)->toContain('<path')
        ->not->toContain('<image')
        ->not->toContain('base64')
        // A fixed width/height on the root would stop CSS sizing it.
        ->not->toContain('width="1254"');
});
