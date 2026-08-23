import tileUrl from '../../images/brand-tile.svg';
import { BRAND_NAME } from '@/lib/brand';

type BrandLockupProps = {
    /** Rendered size of the mark in pixels; the wordmark scales with it. */
    size?: number;
    /** Hide the word, leaving the mark alone (a tight footer, a narrow header). */
    markOnly?: boolean;
};

/**
 * The sloth mark plus the word, as one unit (SLO-170).
 *
 * ⚠️ This uses the TILE — the sloth's face peeking over the branch — and not the
 * full hanging sloth, which was the obvious choice and the wrong one. Rendered
 * side by side at header size, the hanging illustration collapses into a beige
 * smudge with a teal bar across it by about 26px, while the tile still reads as
 * a face at 20. It is an illustration; the tile is an icon, which is what a
 * header needs.
 *
 * That also settles the light-theme problem for free. The loose mark is cream on
 * transparency and nearly vanishes on white; the tile carries its own dark
 * ground, so it reads in both themes with no second artwork to maintain.
 *
 * An `<img>` rather than an inlined SVG component: the artwork has fixed colours
 * with nothing to theme via `currentColor`, and this keeps it out of every JS
 * bundle, cacheable on its own and identical under SSR. Vite hashes the URL, so
 * a future logo change invalidates the cache by itself.
 *
 * `alt=""` when the word is beside it: both say the same thing, and a screen
 * reader announcing "slot4u logo, slot4u" is worse than announcing it once.
 */
export default function BrandLockup({ size = 32, markOnly = false }: BrandLockupProps) {
    return (
        <span className="inline-flex items-center gap-2.5">
            <img
                src={tileUrl}
                alt={markOnly ? BRAND_NAME : ''}
                width={size}
                height={size}
                // Rounded to match the artwork's own corners, so the square
                // bounding box never shows as a hard edge against the page.
                className="block shrink-0 rounded-[28%]"
            />
            {markOnly ? null : (
                <span
                    className="font-semibold tracking-tight"
                    style={{ fontSize: Math.round(size * 0.56) }}
                >
                    {BRAND_NAME}
                </span>
            )}
        </span>
    );
}
