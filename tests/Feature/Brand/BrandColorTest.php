<?php

use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Brand colour tokens (SLO-179; the tokens themselves are SLO-21 / SLO-170)
|--------------------------------------------------------------------------
|
| A tenant picks one colour and the shell has to produce two: the brand colour
| itself and something readable on top of it. The second one has no picker, so
| nothing but this file says it is right — and a wrong answer here is invisible
| in review (the default indigo looks fine either way) and glaring on the one
| tenant who chose a pale brand.
|
*/

it('keeps white on a dark or saturated brand colour', function (string $hex) {
    expect(TenantBranding::readableForeground($hex))->toBe('#ffffff');
})->with([
    'the default indigo' => '#6366f1',
    'near-black' => '#000000',
    'a deep red' => '#b91c1c',
    'a mid blue' => '#2563eb',
    'a forest green' => '#166534',
]);

it('switches to black on a light brand colour', function (string $hex) {
    expect(TenantBranding::readableForeground($hex))->toBe('#000000');
})->with([
    'white' => '#ffffff',
    'yellow' => '#ffff00',
    'the platform teal' => '#22decb',
    'a pale pink' => '#fbcfe8',
    'a light grey' => '#d4d4d4',
]);

it('falls back to white rather than trusting a malformed colour', function (string $hex) {
    // `branding` is JSON on the tenant row: a hand-edited or half-migrated value
    // reaches here without passing the form request's regex.
    expect(TenantBranding::readableForeground($hex))->toBe('#ffffff');
})->with([
    'empty' => '',
    'three-digit shorthand' => '#abc',
    'a colour name' => 'rebeccapurple',
    'trailing junk' => '#6366f1xx',
]);

it('accepts the hex with or without its hash', function () {
    expect(TenantBranding::readableForeground('#ffff00'))
        ->toBe(TenantBranding::readableForeground('ffff00'));
});

it('derives the pair from the tenant it belongs to', function () {
    $branding = TenantBranding::fromArray(['primary_color' => '#ffff00']);

    expect($branding->primaryColor)->toBe('#ffff00')
        ->and($branding->primaryForeground())->toBe('#000000');
});

it('shares both halves of the token with the public shell', function () {
    // The layout overrides `--primary` AND `--primary-foreground` on the tenant
    // subtree; a missing second half leaves the near-white default in place.
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['primary_color' => '#ffff00'],
    ]);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Branding, 'enabled' => true]);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.primary_color', '#ffff00')
            ->where('tenant.primary_foreground', '#000000')
        );
});

it('pairs the default colour with white when branding is off', function () {
    // The unbranded majority: the fallback colour has to bring its own
    // foreground, not inherit whatever the last branded tenant had.
    Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['primary_color' => '#ffff00'],
    ]);

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.primary_color', TenantBranding::DEFAULT_PRIMARY_COLOR)
            ->where('tenant.primary_foreground', '#ffffff')
        );
});

it('keeps the design tokens inlined, so a subtree override can reach them', function () {
    // The regression this guards is silent and was live for months: with a plain
    // `@theme`, Tailwind emits `--color-primary: var(--primary)` into `:root`,
    // the var resolves THERE, and the resolved colour inherits down. Every
    // layout that overrides `--primary` on its own subtree — the tenant public
    // shell with the tenant's brand colour, the marketing shell and the
    // superadmin panel with slot4u's teal — then changed nothing at all, while
    // the code, the docs and the review all read as if it worked.
    //
    // Asserting on the stylesheet rather than the rendered page because the
    // compiled CSS is a build artifact that no test environment produces.
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('@theme inline {')
        ->and($css)->not->toMatch('/@theme\s*\{/');
});

it('does not leave the base layer reading tokens @theme inline stops emitting', function () {
    // The other half of the same switch: `inline` means the `--color-*` names
    // are never written to :root, so anything still saying `var(--color-border)`
    // silently resolves to nothing — an unstyled body, not a compile error.
    $css = file_get_contents(resource_path('css/app.css'));

    $baseLayer = substr($css, (int) strpos($css, '@layer base'));

    expect($baseLayer)->not->toContain('var(--color-');
});
