<?php

namespace App\Support\Mail;

use App\Settings\TenantBranding;

/**
 * The part of the mail brand the superadmin edits (SLO-245), as stored in
 * `platform_settings.mail_brand`. Everything else in {@see MailBrand} is either
 * fixed (body ink, link, lines) or derived from these (the text colour on the
 * header and on the button), so no choice here can produce unreadable text.
 */
final readonly class MailBrandSettings
{
    /** Where the footer sits: its text is ink-muted, and must stay legible. */
    public const string FOOTER_INK = '#5B6B7C';

    /** WCAG AA for body text — the footer is body text. */
    public const float MIN_FOOTER_CONTRAST = 4.5;

    public function __construct(
        public string $headerBackground,
        public string $buttonBackground,
        public string $canvas,
        public ?string $footerText,
        public ?string $logoPath,
    ) {}

    public static function defaults(): self
    {
        $brand = MailBrand::defaults();

        return new self(
            headerBackground: $brand->headerBackground,
            buttonBackground: $brand->buttonBackground,
            canvas: $brand->canvas,
            footerText: null,
            logoPath: null,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $defaults = self::defaults();
        $data ??= [];

        return new self(
            headerBackground: self::hex($data['header_background'] ?? null) ?? $defaults->headerBackground,
            buttonBackground: self::hex($data['button_background'] ?? null) ?? $defaults->buttonBackground,
            canvas: self::hex($data['canvas'] ?? null) ?? $defaults->canvas,
            footerText: self::text($data['footer_text'] ?? null),
            logoPath: self::text($data['logo_path'] ?? null),
        );
    }

    /**
     * @return array{header_background: string, button_background: string, canvas: string, footer_text: string|null, logo_path: string|null}
     */
    public function toArray(): array
    {
        return [
            'header_background' => $this->headerBackground,
            'button_background' => $this->buttonBackground,
            'canvas' => $this->canvas,
            'footer_text' => $this->footerText,
            'logo_path' => $this->logoPath,
        ];
    }

    /** Contrast of the footer's text on the chosen background. */
    public static function footerContrast(string $canvas): float
    {
        return TenantBranding::contrast(self::FOOTER_INK, $canvas);
    }

    private static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtoupper($value)
            : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
