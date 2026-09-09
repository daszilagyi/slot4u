<?php

namespace App\Settings;

use Illuminate\Support\Facades\Storage;

/**
 * Typed view over a tenant's `branding` JSON (SLO-21): primary colour and the
 * stored logo/cover paths (on the `public` disk under a per-tenant prefix). URLs
 * are derived on read so a moved disk never leaves stale absolute links.
 */
final class TenantBranding
{
    public const DEFAULT_PRIMARY_COLOR = '#6366f1';

    /**
     * The dark-theme surface a brand colour has to stay legible on (SLO-214).
     *
     * The card rather than the page background: `--card` (#122234) is the
     * lighter of the two, so a colour that clears the card clears `--background`
     * (#0b1622) as well. One constant instead of two comparisons.
     */
    public const DARK_SURFACE = '#122234';

    /** Where a malformed colour lands in dark mode: the app's own ice. */
    public const DEFAULT_DARK_PRIMARY = '#7cc4f5';

    public function __construct(
        public readonly string $primaryColor = self::DEFAULT_PRIMARY_COLOR,
        public readonly ?string $logoPath = null,
        public readonly ?string $coverPath = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $color = $data['primary_color'] ?? null;

        return new self(
            primaryColor: is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1
                ? $color
                : self::DEFAULT_PRIMARY_COLOR,
            logoPath: self::path($data, 'logo_path'),
            coverPath: self::path($data, 'cover_path'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'primary_color' => $this->primaryColor,
            'logo_path' => $this->logoPath,
            'cover_path' => $this->coverPath,
        ];
    }

    /**
     * Black or white — whichever stays readable as text ON `$hex` (`#rrggbb`).
     *
     * A tenant chooses `primary_color` and nothing else, so the token that sits
     * on top of it has to be derived rather than asked for. The default indigo
     * happens to want white, which is why a fixed near-white went unnoticed —
     * but the picker accepts any hex, and white on a tenant's yellow is
     * unreadable.
     *
     * The switch is at a relative luminance of 0.3, not at the 0.179 point where
     * black and white contrast equally. This colour lands on button labels and
     * badges, so the bar to clear is WCAG's 3:1 for UI text, and 0.3 is where
     * white stops clearing it. The mathematical crossover would flip the default
     * indigo (luminance 0.186, white at 4.45:1) to black text — a change to how
     * every unbranded tenant already looks, bought for contrast nobody was short
     * of.
     */
    public static function readableForeground(string $hex): string
    {
        $luminance = self::luminance($hex);

        if ($luminance === null) {
            return '#ffffff';
        }

        return $luminance > 0.3 ? '#000000' : '#ffffff';
    }

    /**
     * The brand colour, lightened until it can be READ on the dark surface.
     *
     * ⚠️ The inverse of {@see readableForeground()}, and the easiest pair in this
     * class to confuse. That one answers "what text goes ON the brand colour" —
     * a button label. This one answers "what does the brand colour become when
     * it IS the text": the price on a service card, a link, a status badge.
     *
     * The app already does exactly this for its own brand — `:root` is navy and
     * `.dark` swaps `--primary` to ice, because a navy button on a near-navy
     * surface is invisible. The tenant override in `PublicLayout` skipped that
     * step and painted the tenant's colour identically in both themes, so a
     * tenant with a dark brand had its prices rendered at 1.32:1 in the theme
     * that is the DEFAULT (SLO-214). This is that swap, derived per tenant.
     *
     * Hue and saturation are kept and only lightness rises: it still has to read
     * as *their* colour, not as a generic blue.
     */
    public static function readableOnDarkSurface(string $hex, string $surface = self::DARK_SURFACE): string
    {
        $rgb = self::rgb($hex);

        if ($rgb === null) {
            return self::DEFAULT_DARK_PRIMARY;
        }

        [$hue, $saturation, $lightness] = self::toHsl($rgb);

        // 4.5:1 is WCAG AA for body text, and a price is body text. Stepped
        // rather than solved: the relation runs through the sRGB gamma curve,
        // and 1% steps land within a rounding error of the lowest value that
        // clears — which is the one that stays closest to the chosen brand.
        for ($candidate = $lightness; $candidate <= 0.97; $candidate += 0.01) {
            $colour = self::fromHsl($hue, $saturation, $candidate);

            if (self::contrast($colour, $surface) >= 4.5) {
                return $colour;
            }
        }

        // A hue so dark-loving that even 97% lightness will not clear the bar
        // (only near-black surfaces get here). Readability wins over fidelity.
        return self::fromHsl($hue, $saturation, 0.97);
    }

    /** The readable text colour for this tenant's own brand colour. */
    public function primaryForeground(): string
    {
        return self::readableForeground($this->primaryColor);
    }

    /** This tenant's brand colour, as the dark theme has to render it. */
    public function primaryColorDark(): string
    {
        return self::readableOnDarkSurface($this->primaryColor);
    }

    /** What sits ON the dark-theme variant — derived from it, not from the original. */
    public function primaryForegroundDark(): string
    {
        return self::readableForeground($this->primaryColorDark());
    }

    /**
     * WCAG relative luminance of `#rrggbb`, or null when that is not what it is.
     */
    private static function luminance(string $hex): ?float
    {
        $rgb = self::rgb($hex);

        if ($rgb === null) {
            return null;
        }

        // Each sRGB channel linearised, then weighted.
        $channel = static function (int $value): float {
            $srgb = $value / 255;

            return $srgb <= 0.03928
                ? $srgb / 12.92
                : ((($srgb + 0.055) / 1.055) ** 2.4);
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    /** Contrast ratio between two `#rrggbb` colours; 1.0 when either is malformed. */
    private static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        if ($first === null || $second === null) {
            return 1.0;
        }

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{int, int, int}|null
     */
    private static function rgb(string $hex): ?array
    {
        $value = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $value) !== 1) {
            return null;
        }

        return [
            (int) hexdec(substr($value, 0, 2)),
            (int) hexdec(substr($value, 2, 2)),
            (int) hexdec(substr($value, 4, 2)),
        ];
    }

    /**
     * @param  array{int, int, int}  $rgb
     * @return array{float, float, float} Hue, saturation, lightness — each 0..1.
     */
    private static function toHsl(array $rgb): array
    {
        $red = $rgb[0] / 255;
        $green = $rgb[1] / 255;
        $blue = $rgb[2] / 255;

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        // Grey: hue and saturation are meaningless, and dividing by delta would
        // be dividing by zero.
        if ($delta < 1.0e-9) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $lightness > 0.5
            ? $delta / (2 - $max - $min)
            : $delta / ($max + $min);

        $hue = match (true) {
            $max === $red => fmod(($green - $blue) / $delta + ($green < $blue ? 6 : 0), 6),
            $max === $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        };

        return [$hue / 6, $saturation, $lightness];
    }

    private static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        $lightness = min(1.0, max(0.0, $lightness));

        if ($saturation < 1.0e-9) {
            $red = $green = $blue = $lightness;
        } else {
            $q = $lightness < 0.5
                ? $lightness * (1 + $saturation)
                : $lightness + $saturation - $lightness * $saturation;
            $p = 2 * $lightness - $q;

            $red = self::hueToChannel($p, $q, $hue + 1 / 3);
            $green = self::hueToChannel($p, $q, $hue);
            $blue = self::hueToChannel($p, $q, $hue - 1 / 3);
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) round($red * 255),
            (int) round($green * 255),
            (int) round($blue * 255),
        );
    }

    private static function hueToChannel(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }

        if ($t > 1) {
            $t -= 1;
        }

        return match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };
    }

    public function logoUrl(): ?string
    {
        return $this->logoPath !== null ? Storage::disk('public')->url($this->logoPath) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->coverPath !== null ? Storage::disk('public')->url($this->coverPath) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function path(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
