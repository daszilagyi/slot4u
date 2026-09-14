<?php

namespace App\Support\Mail;

use Illuminate\Foundation\Vite;
use Throwable;

/**
 * The look every system email shares (SLO-243/SLO-244): one frame for all 14
 * kinds of mail, whether slot4u or a tenant sends it, so a customer of any
 * tenant recognises the same, trustworthy letter.
 *
 * The values are the docs/21 identity tokens. They live here — and not in the
 * theme stylesheet — so the superadmin brand settings (SLO-245) replace one
 * binding instead of rewriting a CSS file: the theme, the header and the footer
 * all read this object.
 *
 * ⚠️ The logo is a PNG, not the site's SVG tile: Gmail and Outlook drop SVG
 * images. It is a Vite asset, not a `public/` file, because the apex host does
 * not serve `public/` (SLO-233).
 */
final readonly class MailBrand
{
    public function __construct(
        public string $headerBackground,
        public string $headerText,
        public string $buttonBackground,
        public string $buttonText,
        public string $canvas,
        public string $surface,
        public string $ink,
        public string $inkMuted,
        public string $link,
        public string $line,
        public ?string $logoUrl,
        public ?string $footerText,
    ) {}

    public static function defaults(): self
    {
        return new self(
            headerBackground: '#0D1B2A',
            headerText: '#FFFFFF',
            buttonBackground: '#F4B942',
            buttonText: '#0D1B2A',
            canvas: '#F5F7FA',
            surface: '#FFFFFF',
            ink: '#14212F',
            inkMuted: '#5B6B7C',
            link: '#1B4F72',
            line: '#DCE4EC',
            logoUrl: self::defaultLogoUrl(),
            footerText: null,
        );
    }

    /**
     * Absolute URL of the PNG tile, or null when no build is available (a test
     * run without assets) — the header then shows the wordmark alone rather than
     * a broken image.
     */
    private static function defaultLogoUrl(): ?string
    {
        try {
            return app(Vite::class)->asset('resources/images/mail/brand-tile.png');
        } catch (Throwable) {
            return null;
        }
    }
}
