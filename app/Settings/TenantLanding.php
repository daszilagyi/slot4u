<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\LandingTemplate;

/**
 * A tenant's landing template and the words that fill it (SLO-238, docs/25).
 *
 * Stored in `tenants.landing`. Everything here is the TENANT's content — their
 * tagline, their FAQ, what their clients said — and it is shown as they wrote
 * it. The template's own labels ("Miben segíthetünk?") are UI text and live in
 * the lang file; only what differs from one practice to the next lives here.
 *
 * ⚠️ Read defensively. The column is JSON a future editor, a seeder or a hand
 * in tinker may write, and a public page must not break over a missing key or
 * a list nested one level wrong: every field falls back to empty, every list is
 * capped, and anything that is not a string is dropped rather than cast.
 */
final class TenantLanding
{
    /**
     * Icons a template can draw; anything else falls back to the leaf. The glam
     * set (SLO-241) sits in the same list — each template maps what it knows.
     */
    public const ICONS = ['leaf', 'heart', 'people', 'shield', 'calendar', 'scissors', 'flower', 'sparkles', 'hand', 'foot'];

    /** A template art slot name (`cat-hair`, `staff-1`): never a path or a URL. */
    private const PHOTO_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/';

    /**
     * @param  list<array{icon: string, label: string}>  $highlights
     * @param  list<string>  $bubbles
     * @param  list<array{icon: string, title: string, text: string}>  $whyItems
     * @param  list<string>  $aboutChips
     * @param  list<array{name: string, text: string}>  $testimonials
     * @param  list<array{q: string, a: string}>  $faq
     * @param  list<string>  $headline
     * @param  list<string>  $neon
     * @param  list<array{name: string, subtitle: string, icon: string, photo: string|null}>  $categoryCards
     * @param  list<array{name: string, badge: string|null, description: string|null, photo: string|null}>  $featured
     * @param  list<array{name: string, skills: string|null, photo: string|null}>  $team
     */
    private function __construct(
        public readonly LandingTemplate $template,
        public readonly ?string $brandTitle,
        public readonly ?string $brandSubtitle,
        public readonly ?string $tagline,
        public readonly ?string $lead,
        public readonly array $highlights,
        public readonly array $bubbles,
        public readonly ?string $motto,
        public readonly ?string $whyQuote,
        public readonly array $whyItems,
        public readonly ?string $aboutName,
        public readonly ?string $aboutTitle,
        public readonly ?string $aboutBio,
        public readonly array $aboutChips,
        public readonly array $testimonials,
        public readonly array $faq,
        public readonly array $headline = [],
        public readonly array $neon = [],
        public readonly array $categoryCards = [],
        public readonly array $featured = [],
        public readonly array $team = [],
        public readonly ?string $quickService = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $about = is_array($data['about'] ?? null) ? $data['about'] : [];

        return new self(
            template: LandingTemplate::tryFrom((string) ($data['template'] ?? '')) ?? LandingTemplate::Default,
            brandTitle: self::str($data['brand_title'] ?? null),
            brandSubtitle: self::str($data['brand_subtitle'] ?? null),
            tagline: self::str($data['tagline'] ?? null),
            lead: self::str($data['lead'] ?? null),
            highlights: self::records($data['highlights'] ?? null, 3, fn (array $item) => ($label = self::str($item['label'] ?? null)) === null ? null : [
                'icon' => self::icon($item['icon'] ?? null),
                'label' => $label,
            ]),
            bubbles: self::strings($data['bubbles'] ?? null, 2),
            motto: self::str($data['motto'] ?? null),
            whyQuote: self::str($data['why_quote'] ?? null),
            whyItems: self::records($data['why_items'] ?? null, 4, fn (array $item) => ($title = self::str($item['title'] ?? null)) === null ? null : [
                'icon' => self::icon($item['icon'] ?? null),
                'title' => $title,
                'text' => self::str($item['text'] ?? null) ?? '',
            ]),
            aboutName: self::str($about['name'] ?? null),
            aboutTitle: self::str($about['title'] ?? null),
            aboutBio: self::str($about['bio'] ?? null),
            aboutChips: self::strings($about['chips'] ?? null, 6),
            testimonials: self::records($data['testimonials'] ?? null, 12, fn (array $item) => ($text = self::str($item['text'] ?? null)) === null ? null : [
                'name' => self::str($item['name'] ?? null) ?? '',
                'text' => $text,
            ]),
            faq: self::records($data['faq'] ?? null, 12, fn (array $item) => ($q = self::str($item['q'] ?? null)) === null || ($a = self::str($item['a'] ?? null)) === null ? null : [
                'q' => $q,
                'a' => $a,
            ]),
            // The glam template's content (SLO-241). The names on category,
            // featured and team entries are matched against the tenant's REAL
            // categories, services and staff — the landing only adds a photo
            // and a line of copy to something that exists.
            headline: self::strings($data['headline'] ?? null, 3),
            neon: self::strings($data['neon'] ?? null, 2),
            categoryCards: self::records($data['categories'] ?? null, 8, fn (array $item) => ($name = self::str($item['name'] ?? null)) === null ? null : [
                'name' => $name,
                'subtitle' => self::str($item['subtitle'] ?? null) ?? '',
                'icon' => self::icon($item['icon'] ?? null),
                'photo' => self::photo($item['photo'] ?? null),
            ]),
            featured: self::records($data['featured'] ?? null, 8, fn (array $item) => ($name = self::str($item['name'] ?? null)) === null ? null : [
                'name' => $name,
                'badge' => self::str($item['badge'] ?? null),
                'description' => self::str($item['description'] ?? null),
                'photo' => self::photo($item['photo'] ?? null),
            ]),
            team: self::records($data['team'] ?? null, 6, fn (array $item) => ($name = self::str($item['name'] ?? null)) === null ? null : [
                'name' => $name,
                'skills' => self::str($item['skills'] ?? null),
                'photo' => self::photo($item['photo'] ?? null),
            ]),
            quickService: self::str($data['quick_service'] ?? null),
        );
    }

    public function usesCalm(): bool
    {
        return $this->template === LandingTemplate::Calm;
    }

    public function usesGlam(): bool
    {
        return $this->template === LandingTemplate::Glam;
    }

    /**
     * The shape the public page reads.
     *
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [
            'template' => $this->template->value,
            'brand_title' => $this->brandTitle,
            'brand_subtitle' => $this->brandSubtitle,
            'tagline' => $this->tagline,
            'lead' => $this->lead,
            'highlights' => $this->highlights,
            'bubbles' => $this->bubbles,
            'motto' => $this->motto,
            'why_quote' => $this->whyQuote,
            'why_items' => $this->whyItems,
            'about' => [
                'name' => $this->aboutName,
                'title' => $this->aboutTitle,
                'bio' => $this->aboutBio,
                'chips' => $this->aboutChips,
            ],
            'testimonials' => $this->testimonials,
            'faq' => $this->faq,
            'headline' => $this->headline,
            'neon' => $this->neon,
            'categories' => $this->categoryCards,
            'featured' => $this->featured,
        ];
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function photo(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::PHOTO_PATTERN, $value) === 1 ? $value : null;
    }

    private static function icon(mixed $value): string
    {
        return is_string($value) && in_array($value, self::ICONS, true) ? $value : 'leaf';
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, int $max): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values(array_filter(array_map(self::str(...), $value))), 0, $max);
    }

    /**
     * @template T of array<string, string|null>
     *
     * @param  callable(array<string, mixed>): (T|null)  $map
     * @return list<T>
     */
    private static function records(mixed $value, int $max, callable $map): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $item) {
            if (is_array($item) && ($record = $map($item)) !== null) {
                $records[] = $record;
            }
        }

        return array_slice($records, 0, $max);
    }
}
