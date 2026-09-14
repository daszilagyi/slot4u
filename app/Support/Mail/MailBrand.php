<?php

namespace App\Support\Mail;

use App\Settings\TenantBranding;
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
    /** The slot4u tile as a PNG, relative to the project root (a Vite input). */
    public const string DEFAULT_LOGO = 'resources/images/mail/brand-tile.png';

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
     * The brand as the superadmin set it (SLO-245). Only the three colours, the
     * footer and the logo are chosen; the text on the header and on the button
     * is derived, so a colour choice cannot make either unreadable.
     */
    public static function fromSettings(MailBrandSettings $settings, ?string $logoUrl): self
    {
        $defaults = self::defaults();

        return new self(
            headerBackground: $settings->headerBackground,
            headerText: self::readableTextOn($settings->headerBackground),
            buttonBackground: $settings->buttonBackground,
            buttonText: self::readableTextOn($settings->buttonBackground),
            canvas: $settings->canvas,
            surface: $defaults->surface,
            ink: $defaults->ink,
            inkMuted: MailBrandSettings::FOOTER_INK,
            link: $defaults->link,
            line: $defaults->line,
            logoUrl: $logoUrl,
            footerText: $settings->footerText,
        );
    }

    /**
     * White, navy or black — the first that reads at WCAG AA (4.5:1) on `$hex`.
     *
     * Navy before black so the default yellow button keeps its navy label.
     * Black last because it always clears where the other two do not: on a
     * mid grey white reaches 4.48:1 and navy 3.8:1, while the better of black
     * and white never drops below 4.58:1.
     */
    public static function readableTextOn(string $hex): string
    {
        foreach (['#FFFFFF', '#0D1B2A'] as $candidate) {
            if (TenantBranding::contrast($candidate, $hex) >= 4.5) {
                return $candidate;
            }
        }

        return TenantBranding::contrast('#000000', $hex) >= TenantBranding::contrast('#FFFFFF', $hex)
            ? '#000000'
            : '#FFFFFF';
    }

    /**
     * Absolute URL of the PNG tile, or null when no build is available (a test
     * run without assets) — the header then shows the wordmark alone rather than
     * a broken image.
     */
    public static function defaultLogoUrl(): ?string
    {
        try {
            return app(Vite::class)->asset(self::DEFAULT_LOGO);
        } catch (Throwable) {
            return null;
        }
    }
}
