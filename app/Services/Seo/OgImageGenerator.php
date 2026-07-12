<?php

namespace App\Services\Seo;

use App\Models\Tenant;
use App\Settings\TenantBranding;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a tenant-branded 1200×630 Open Graph share image with GD (no headless
 * browser — dev WSL has no Chrome, docs/01/SLO-89): brand-colour background, the
 * tenant name in the bundled Inter font, and a small "slot4u" wordmark. Cached to
 * the public disk keyed by a hash of the branding inputs, so a colour/logo/name
 * change yields a fresh file and stale crawler caches are busted via the ?v query
 * on the homepage og:image URL.
 */
class OgImageGenerator
{
    private const WIDTH = 1200;

    private const HEIGHT = 630;

    /**
     * Absolute filesystem path of the cached PNG on the public disk, generating it
     * on first request. Idempotent: the same branding inputs reuse the same file.
     */
    public function pathForTenant(Tenant $tenant): string
    {
        $relative = $this->relativePath($tenant);

        if (Storage::disk('public')->exists($relative)) {
            return Storage::disk('public')->path($relative);
        }

        return $this->generate($tenant, $relative);
    }

    /**
     * Short hash of the branding inputs — used both in the cached filename and as
     * the homepage `?v=` cache-buster so a rebrand invalidates crawler caches.
     */
    public function cacheKey(Tenant $tenant): string
    {
        $branding = TenantBranding::fromArray($tenant->branding);

        return substr(sha1($tenant->name.'|'.$branding->primaryColor.'|'.($branding->logoPath ?? '')), 0, 12);
    }

    private function relativePath(Tenant $tenant): string
    {
        return 'og/'.$tenant->getKey().'-'.$this->cacheKey($tenant).'.png';
    }

    private function generate(Tenant $tenant, string $relative): string
    {
        $branding = TenantBranding::fromArray($tenant->branding);
        [$r, $g, $b] = $this->parseColor($branding->primaryColor);

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        // GD returns false on failure; the caller streams this path so a hard
        // failure would surface as a broken image — but GD is verified available.
        $bg = imagecolorallocate($img, $r, $g, $b);
        imagefilledrectangle($img, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        // A subtly darker footer band anchors the wordmark without a second asset.
        $band = imagecolorallocate($img, (int) ($r * 0.82), (int) ($g * 0.82), (int) ($b * 0.82));
        imagefilledrectangle($img, 0, self::HEIGHT - 96, self::WIDTH, self::HEIGHT, $band);

        // Contrast-aware text colour: light brands get near-black text, dark ones white.
        $useDark = $this->relativeLuminance($r, $g, $b) > 0.6;
        $text = $useDark
            ? imagecolorallocate($img, 17, 17, 17)
            : imagecolorallocate($img, 255, 255, 255);

        $font = resource_path('fonts/Inter-SemiBold.ttf');

        $this->drawTenantName($img, $tenant->name, $font, $text);
        $this->drawLogo($img, $branding);
        $this->drawWordmark($img, $font, $text);

        Storage::disk('public')->makeDirectory('og');
        $absolute = Storage::disk('public')->path($relative);
        $written = imagepng($img, $absolute);
        imagedestroy($img);

        // Guard the write: on failure (disk full / permissions) the caller would
        // otherwise hand a non-existent path to response()->file() and 500 with an
        // opaque FileException — surface a clear error here instead.
        if ($written === false || ! is_file($absolute)) {
            throw new \RuntimeException("Failed to write OG image to {$relative}.");
        }

        return $absolute;
    }

    /**
     * Draws the tenant name centred, word-wrapped to at most 3 lines within ~1000px,
     * with an ellipsis if it still overflows.
     *
     * @param  \GdImage  $img
     */
    private function drawTenantName($img, string $name, string $font, int $color): void
    {
        $size = 64;
        $maxWidth = 1000;
        $lines = $this->wrap($name, $font, $size, $maxWidth, 3);

        $lineHeight = 92;
        $totalHeight = count($lines) * $lineHeight;
        // Centre the block vertically in the area above the footer band.
        $startY = (int) ((self::HEIGHT - 96) / 2 - $totalHeight / 2 + $lineHeight * 0.72);

        foreach ($lines as $i => $line) {
            $box = imagettfbbox($size, 0, $font, $line);
            $width = $box[2] - $box[0];
            $x = (int) ((self::WIDTH - $width) / 2);
            $y = $startY + $i * $lineHeight;
            imagettftext($img, $size, 0, $x, $y, $color, $font, $line);
        }
    }

    /**
     * Greedy word-wrap measured with imagettfbbox. Truncates to $maxLines with an
     * ellipsis; a single over-long word is hard-truncated character-wise.
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === [] || $words === ['']) {
            return [''];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->textWidth($candidate, $font, $size) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
            // A word wider than the line on its own gets hard-truncated.
            $current = $this->textWidth($word, $font, $size) <= $maxWidth
                ? $word
                : $this->truncateToWidth($word, $font, $size, $maxWidth);

            if (count($lines) >= $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
        }

        // If content was cut off, mark the last line with an ellipsis.
        $consumed = implode(' ', $lines);
        if (mb_strlen(trim($consumed)) < mb_strlen(trim($text))) {
            $last = array_key_last($lines);
            $lines[$last] = $this->truncateToWidth($lines[$last].' …', $font, $size, $maxWidth);
        }

        return $lines;
    }

    private function truncateToWidth(string $text, string $font, int $size, int $maxWidth): string
    {
        while ($text !== '' && $this->textWidth($text.'…', $font, $size) > $maxWidth) {
            $text = mb_substr($text, 0, mb_strlen($text) - 1);
        }

        return rtrim($text).'…';
    }

    private function textWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return (int) ($box[2] - $box[0]);
    }

    /**
     * Draws the tenant logo top-left, scaled to ~120px height, if a readable PNG/JPG
     * exists on the public disk. A broken/unsupported logo must never break the image.
     *
     * @param  \GdImage  $img
     */
    private function drawLogo($img, TenantBranding $branding): void
    {
        if ($branding->logoPath === null) {
            return;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($branding->logoPath)) {
                return;
            }

            $path = $disk->path($branding->logoPath);
            $info = @getimagesize($path);
            if ($info === false) {
                return;
            }

            $logo = match ($info[2]) {
                IMAGETYPE_PNG => @imagecreatefrompng($path),
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                default => false,
            };
            if ($logo === false) {
                return;
            }

            $targetH = 120;
            $srcW = imagesx($logo);
            $srcH = imagesy($logo);
            $targetW = (int) round($srcW * ($targetH / $srcH));

            imagealphablending($img, true);
            imagecopyresampled($img, $logo, 72, 72, 0, 0, $targetW, $targetH, $srcW, $srcH);
            imagedestroy($logo);
        } catch (\Throwable) {
            // A cosmetic logo is best-effort; never let it break the OG image.
        }
    }

    /**
     * @param  \GdImage  $img
     */
    private function drawWordmark($img, string $font, int $color): void
    {
        $size = 28;
        $box = imagettfbbox($size, 0, $font, 'slot4u');
        $width = $box[2] - $box[0];
        $x = (int) ((self::WIDTH - $width) / 2);
        imagettftext($img, $size, 0, $x, self::HEIGHT - 34, $color, $font, 'slot4u');
    }

    /**
     * @return array{0:int,1:int,2:int}
     */
    private function parseColor(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function relativeLuminance(int $r, int $g, int $b): float
    {
        // Simple perceptual luminance (sRGB coefficients), 0..1.
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    }
}
