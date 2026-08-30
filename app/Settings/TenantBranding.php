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
        $value = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $value) !== 1) {
            return '#ffffff';
        }

        // WCAG relative luminance: each sRGB channel linearised, then weighted.
        $channel = static function (int $offset) use ($value): float {
            $srgb = hexdec(substr($value, $offset, 2)) / 255;

            return $srgb <= 0.03928
                ? $srgb / 12.92
                : ((($srgb + 0.055) / 1.055) ** 2.4);
        };

        $luminance = 0.2126 * $channel(0) + 0.7152 * $channel(2) + 0.0722 * $channel(4);

        return $luminance > 0.3 ? '#000000' : '#ffffff';
    }

    /** The readable text colour for this tenant's own brand colour. */
    public function primaryForeground(): string
    {
        return self::readableForeground($this->primaryColor);
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
