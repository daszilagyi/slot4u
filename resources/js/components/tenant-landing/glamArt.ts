/*
 * The glam template's pictures (SLO-241, docs/26).
 *
 * The sloths and the flower mark were cut from the GlamZone design's generated
 * sheets; the photos are Pexels. Sources and crops are in
 * `resources/images/glam/README.md`. Bundled through Vite, like every landing
 * image (SLO-233).
 *
 * Two kinds of slot:
 *   - fixed: the template always draws them in the same place (`hero`, `phone`,
 *     `dryer`, `mark`, `salon`);
 *   - named: the tenant's landing content picks them by key for a category,
 *     a featured service or a team card. An unknown key draws the fallback.
 */
import type { LandingImage } from '@/components/landing/landingArt';

import catBeautyJpg from '../../../images/glam/cat-beauty.jpg';
import catBeautyWebp from '../../../images/glam/cat-beauty.webp';
import catFeetJpg from '../../../images/glam/cat-feet.jpg';
import catFeetWebp from '../../../images/glam/cat-feet.webp';
import catHairJpg from '../../../images/glam/cat-hair.jpg';
import catHairWebp from '../../../images/glam/cat-hair.webp';
import catNailsJpg from '../../../images/glam/cat-nails.jpg';
import catNailsWebp from '../../../images/glam/cat-nails.webp';
import heroPng from '../../../images/glam/hero-sloth.png';
import heroWebp from '../../../images/glam/hero-sloth.webp';
import markPng from '../../../images/glam/mark-flower.png';
import markWebp from '../../../images/glam/mark-flower.webp';
import salonJpg from '../../../images/glam/salon.jpg';
import salonWebp from '../../../images/glam/salon.webp';
import dryerPng from '../../../images/glam/sloth-dryer.png';
import dryerWebp from '../../../images/glam/sloth-dryer.webp';
import phonePng from '../../../images/glam/sloth-phone.png';
import phoneWebp from '../../../images/glam/sloth-phone.webp';
import staff1Jpg from '../../../images/glam/staff-1.jpg';
import staff1Webp from '../../../images/glam/staff-1.webp';
import staff2Jpg from '../../../images/glam/staff-2.jpg';
import staff2Webp from '../../../images/glam/staff-2.webp';
import staff3Jpg from '../../../images/glam/staff-3.jpg';
import staff3Webp from '../../../images/glam/staff-3.webp';
import svcColourJpg from '../../../images/glam/svc-colour.jpg';
import svcColourWebp from '../../../images/glam/svc-colour.webp';
import svcCutJpg from '../../../images/glam/svc-cut.jpg';
import svcCutWebp from '../../../images/glam/svc-cut.webp';
import svcFacialJpg from '../../../images/glam/svc-facial.jpg';
import svcFacialWebp from '../../../images/glam/svc-facial.webp';
import svcGelJpg from '../../../images/glam/svc-gel.jpg';
import svcGelWebp from '../../../images/glam/svc-gel.webp';

/** The pictures the template itself places. */
export const GLAM_FIXED = {
    hero: { webp: heroWebp, png: heroPng, width: 1100, height: 848 },
    phone: { webp: phoneWebp, png: phonePng, width: 242, height: 251 },
    dryer: { webp: dryerWebp, png: dryerPng, width: 265, height: 282 },
    mark: { webp: markWebp, png: markPng, width: 151, height: 154 },
    salon: { webp: salonWebp, png: salonJpg, width: 1100, height: 760 },
} satisfies Record<string, LandingImage>;

// Photos fall back to JPEG, not PNG: `png` is only the <img> fallback URL.
const PHOTOS: Record<string, LandingImage> = {
    'cat-hair': { webp: catHairWebp, png: catHairJpg, width: 720, height: 520 },
    'cat-beauty': {
        webp: catBeautyWebp,
        png: catBeautyJpg,
        width: 720,
        height: 520,
    },
    'cat-nails': {
        webp: catNailsWebp,
        png: catNailsJpg,
        width: 720,
        height: 520,
    },
    'cat-feet': { webp: catFeetWebp, png: catFeetJpg, width: 720, height: 520 },
    'svc-cut': { webp: svcCutWebp, png: svcCutJpg, width: 640, height: 440 },
    'svc-colour': {
        webp: svcColourWebp,
        png: svcColourJpg,
        width: 640,
        height: 440,
    },
    'svc-facial': {
        webp: svcFacialWebp,
        png: svcFacialJpg,
        width: 640,
        height: 440,
    },
    'svc-gel': { webp: svcGelWebp, png: svcGelJpg, width: 640, height: 440 },
    'staff-1': { webp: staff1Webp, png: staff1Jpg, width: 240, height: 340 },
    'staff-2': { webp: staff2Webp, png: staff2Jpg, width: 240, height: 340 },
    'staff-3': { webp: staff3Webp, png: staff3Jpg, width: 240, height: 340 },
};

/** A photo the tenant's content named, or null when there is no such key. */
export function glamPhoto(key: string | null): LandingImage | null {
    return key !== null ? (PHOTOS[key] ?? null) : null;
}
