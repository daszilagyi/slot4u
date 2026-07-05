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
