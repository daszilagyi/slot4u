<?php

use App\Enums\Feature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Settings\TenantBranding;
use App\Tenancy\TenantManager;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Brand colour tokens (SLO-21 / SLO-170)
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
    // layout that overrides `--primary` on its own subtree then changed nothing
    // at all, while the code, the docs and the review all read as if it worked.
    //
    // ⚠️ Since SLO-201 there is exactly ONE such override left — the tenant
    // public shell painting the tenant's own colour. The marketing and
    // superadmin shells used to override it too, with slot4u's teal; they no
    // longer need to, because the identity IS the default now. That makes this
    // guard MORE important, not less: one caller is easier to overlook when
    // somebody reaches for a plain `@theme`.
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

/*
|--------------------------------------------------------------------------
| The dark-theme variant (SLO-214)
|--------------------------------------------------------------------------
|
| A tenant still picks one colour, and dark mode is the DEFAULT theme — so the
| colour that gets painted there has to be derived too. Before this, the brand
| colour went out identically in both themes, and a tenant with a dark brand had
| its service prices rendered at 1.32:1 on the dark card. Nothing failed: the
| suite was green, because no test had ever read a computed contrast ratio.
|
*/

/** Contrast ratio between two #rrggbb colours — the WCAG formula, independently. */
function brandContrast(string $a, string $b): float
{
    $luminance = static function (string $hex): float {
        $value = ltrim($hex, '#');
        $channel = static function (int $offset) use ($value): float {
            $srgb = hexdec(substr($value, $offset, 2)) / 255;

            return $srgb <= 0.03928 ? $srgb / 12.92 : ((($srgb + 0.055) / 1.055) ** 2.4);
        };

        return 0.2126 * $channel(0) + 0.7152 * $channel(2) + 0.0722 * $channel(4);
    };

    $first = $luminance($a);
    $second = $luminance($b);

    return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
}

it('⚠️ makes any brand colour readable as text on the dark surface', function (string $hex) {
    $dark = TenantBranding::readableOnDarkSurface($hex);

    // 4.5:1 is WCAG AA for body text, and a price on a service card is body text.
    expect(brandContrast($dark, TenantBranding::DARK_SURFACE))->toBeGreaterThanOrEqual(4.5)
        // The page background is darker than the card, so it can only be easier.
        ->and(brandContrast($dark, '#0b1622'))->toBeGreaterThanOrEqual(4.5);
})->with([
    // The one that was measured in the browser at 1.32:1 (demo-autoszerviz).
    'a dark slate' => '#2f3640',
    // ⚠️ The DEFAULT. Unbranded tenants were failing too, at 3.60:1.
    'the default indigo' => '#6366f1',
    'our own navy' => '#0d1b2a',
    'near-black' => '#000000',
    'a deep red' => '#b91c1c',
    'a forest green' => '#166534',
    'a mid blue' => '#2563eb',
]);

it('leaves a colour alone when it is already readable there', function (string $hex) {
    // Not "lighten everything": a tenant who picked a bright colour keeps the
    // exact colour they picked. Only the unreadable ones move.
    expect(TenantBranding::readableOnDarkSurface($hex))->toBe($hex);
})->with([
    'yellow' => '#ffff00',
    'the platform teal' => '#22decb',
    'ice' => '#7cc4f5',
]);

it('keeps the tenant hue rather than substituting a generic colour', function () {
    // It still has to read as *their* brand. Red stays red: the red channel
    // dominates before and after.
    $dark = TenantBranding::readableOnDarkSurface('#b91c1c');
    $rgb = sscanf($dark, '#%02x%02x%02x');

    expect($rgb[0])->toBeGreaterThan($rgb[1])
        ->and($rgb[0])->toBeGreaterThan($rgb[2]);
});

it('falls back to ice rather than trusting a malformed colour', function (string $hex) {
    expect(TenantBranding::readableOnDarkSurface($hex))
        ->toBe(TenantBranding::DEFAULT_DARK_PRIMARY);
})->with([
    'empty' => '',
    'three-digit shorthand' => '#abc',
    'a colour name' => 'rebeccapurple',
]);

it('shares the dark pair with the public shell too', function () {
    // ⚠️ The theme is a client-side choice, so the server cannot send "the"
    // colour — it sends both pairs and app.css picks. A missing dark pair means
    // the stylesheet resolves `--primary` to nothing on the tenant subtree.
    $tenant = Tenant::factory()->active()->create([
        'slug' => 'acme',
        'branding' => ['primary_color' => '#2f3640'],
    ]);

    app(TenantManager::class)->set($tenant);
    TenantFeature::factory()->create(['feature_code' => Feature::Branding, 'enabled' => true]);
    app(TenantManager::class)->forget();

    $this->get(tenantHost('acme'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.primary_color', '#2f3640')
            ->where('tenant.primary_color_dark', TenantBranding::readableOnDarkSurface('#2f3640'))
            // Derived from the DARK variant, not from the original — white on a
            // lightened brand is the same bug one theme over.
            ->where('tenant.primary_foreground_dark', TenantBranding::readableForeground(
                TenantBranding::readableOnDarkSurface('#2f3640'),
            ))
        );
});
