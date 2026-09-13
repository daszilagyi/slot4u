<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The illustrations the landing page has a place for (SLO-229), and which of
 * them actually exist.
 *
 * The "Slot4u Landing" design leaves room for the sloth in five poses. The
 * artwork is its own deliverable (SLO-202), so the page cannot assume it: a
 * slot whose file is missing is reported as null and the section lays out
 * without it, instead of shipping a broken-image icon or a visible empty frame.
 *
 * ⚠️ Decided here, on the server, rather than with an `onError` in the browser.
 * The page is server-rendered, and a missing image discovered client-side is a
 * 404 in every visitor's network tab plus a layout that jumps after hydration.
 * A `file_exists` per slot costs nothing next to that.
 *
 * Drop a file named after its slot into `public/brand/` — PNG with transparency,
 * WebP or SVG — and it appears on the next request. No deploy-side config.
 */
final class MarketingArt
{
    /**
     * Slot name => what goes there (the design's own placeholder text, for
     * whoever produces the artwork).
     */
    public const SLOTS = [
        'hero' => 'Superhero sloth, flying (transparent)',
        'step-register' => 'Laptop illustration',
        'step-setup' => 'Calendar + cog illustration',
        'step-bookings' => 'Celebrating sloth',
        'sofa' => 'Sloth in an armchair with a laptop and a GOOD SLOTS mug',
        'peek' => 'Peeking sloth',
        'cta' => 'Sloth on a cushion with a laptop',
    ];

    /** In order of preference: a vector scales, WebP is smaller than PNG. */
    private const EXTENSIONS = ['svg', 'webp', 'png'];

    /**
     * @return array<string, string|null> slot => public URL, or null when absent
     */
    public static function available(): array
    {
        $art = [];

        foreach (array_keys(self::SLOTS) as $slot) {
            $art[$slot] = self::find($slot);
        }

        return $art;
    }

    private static function find(string $slot): ?string
    {
        foreach (self::EXTENSIONS as $extension) {
            $relative = "brand/{$slot}.{$extension}";
            $path = public_path($relative);

            if (is_file($path)) {
                // The file's mtime rides along, so replacing the artwork under
                // the same name is not hidden behind a cached copy.
                return '/'.$relative.'?v='.filemtime($path);
            }
        }

        return null;
    }
}
