<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use Illuminate\Console\Command;

/**
 * Builds the favicon ladder and the platform OG image from the brand tile
 * (SLO-170).
 *
 * ⚠️ Takes a rasterised source PATH rather than reading the committed SVG,
 * because nothing on this host can rasterise SVG — no ImageMagick, no librsvg,
 * no headless renderer. The vector stays the source of truth in
 * `resources/images/brand-tile.svg`; regenerating means exporting it to a square
 * PNG once and pointing this command at it. Saying that out loud beats a command
 * that quietly reads a stale raster and pretends it read the vector.
 *
 * The outputs are committed. They are small, they change roughly never, and a
 * deploy that had to generate them would need a toolchain the shared host does
 * not have (docs/16).
 */
class BuildBrandIcons extends Command
{
    protected $signature = 'brand:icons {source : A square PNG export of the brand tile}';

    protected $description = 'Regenerate the favicons and the platform OG image from a tile export';

    /** Every size a browser or platform actually asks for. */
    private const ICONS = [
        'favicon-32.png' => 32,
        'apple-touch-icon.png' => 180,
        'icon-192.png' => 192,
        'icon-512.png' => 512,
    ];

    /** The tile's own background, sampled from the artwork (SLO-170). */
    private const TILE_BACKGROUND = [0x09, 0x10, 0x20];

    public function handle(): int
    {
        $path = (string) $this->argument('source');

        if (! is_file($path)) {
            $this->components->error("No such file: {$path}");

            return self::FAILURE;
        }

        $source = @imagecreatefrompng($path);

        if (! $source instanceof GdImage) {
            $this->components->error('That is not a PNG this host can read.');

            return self::FAILURE;
        }

        $directory = public_path('img');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        foreach (self::ICONS as $name => $size) {
            $this->writeIcon($source, $directory.'/'.$name, $size);
            $this->report($directory.'/'.$name);
        }

        $this->writeOgImage($source, $directory.'/og-image.png');
        $this->report($directory.'/og-image.png');

        return self::SUCCESS;
    }

    /**
     * One square icon, resampled with the alpha channel intact.
     *
     * Transparency is kept rather than flattened onto a guessed colour: the tile
     * has rounded corners and a glow, and a matte in the wrong shade would show
     * as a square halo on every launcher that composites it itself.
     */
    private function writeIcon(GdImage $source, string $path, int $size): void
    {
        $icon = imagecreatetruecolor($size, $size);
        imagealphablending($icon, false);
        imagesavealpha($icon, true);
        imagefill($icon, 0, 0, (int) imagecolorallocatealpha($icon, 0, 0, 0, 127));

        imagecopyresampled(
            $icon, $source,
            0, 0, 0, 0,
            $size, $size, imagesx($source), imagesy($source),
        );

        imagepng($icon, $path, 9);
        imagedestroy($icon);
    }

    /**
     * The 1200×630 card a link preview shows.
     *
     * ⚠️ Opaque, and deliberately so. A transparent OG image is composited by
     * each platform onto its own colour — cream artwork on a white card is close
     * to invisible, which is the exact failure this image exists to avoid. The
     * tile's own background colour is painted underneath instead.
     */
    private function writeOgImage(GdImage $source, string $path): void
    {
        [$width, $height] = [1200, 630];
        $card = imagecreatetruecolor($width, $height);

        [$r, $g, $b] = self::TILE_BACKGROUND;
        imagefilledrectangle($card, 0, 0, $width, $height, (int) imagecolorallocate($card, $r, $g, $b));

        // The tile, centred and generously sized: a preview card is read at
        // thumbnail size in a chat list, so the mark has to survive being small.
        $tile = 420;
        imagecopyresampled(
            $card, $source,
            (int) (($width - $tile) / 2), (int) (($height - $tile) / 2 - 40),
            0, 0,
            $tile, $tile, imagesx($source), imagesy($source),
        );

        $this->drawWordmark($card, $width, (int) (($height + $tile) / 2 - 10));

        imagepng($card, $path, 9);
        imagedestroy($card);
    }

    /**
     * The name under the mark, in the same Inter the tenant OG card uses
     * (SLO-89) — one typeface across both, so a slot4u preview and a tenant
     * preview read as the same product.
     */
    private function drawWordmark(GdImage $card, int $width, int $baseline): void
    {
        $font = resource_path('fonts/Inter-SemiBold.ttf');

        if (! is_file($font)) {
            return;
        }

        $size = 54;
        $text = (string) config('app.name', 'slot4u');
        $box = imagettfbbox($size, 0, $font, $text);
        $textWidth = abs($box[2] - $box[0]);

        imagettftext(
            $card, $size, 0,
            (int) (($width - $textWidth) / 2), $baseline + $size,
            (int) imagecolorallocate($card, 0xFA, 0xEC, 0xD7),
            $font, $text,
        );
    }

    private function report(string $path): void
    {
        $this->components->twoColumnDetail(
            str_replace(public_path().'/', '', $path),
            (string) round(filesize($path) / 1024).' KB',
        );
    }
}
