/*
 * The calm template's illustrations (SLO-238, docs/25).
 *
 * The artwork was made for the Lélekút design and handed over separately (the
 * design project's files exceed its export limit); the sources and how each
 * file was cut are in `resources/images/calm/README.md`. A slot without a file
 * draws its soft placeholder shape instead — the layout never waits for, or
 * breaks without, a picture.
 *
 * To add one: put the file in `resources/images/calm/`, import it here and add
 * the entry. Bundled through Vite, like every landing image (SLO-233).
 */
import type { LandingImage } from '@/components/landing/landingArt';

import aboutJpg from '../../../images/calm/about-room.jpg';
import aboutWebp from '../../../images/calm/about-room.webp';
import contactPng from '../../../images/calm/contact-room.png';
import contactWebp from '../../../images/calm/contact-room.webp';
import heroPng from '../../../images/calm/hero-session.png';
import heroWebp from '../../../images/calm/hero-session.webp';
import leavesPng from '../../../images/calm/leaves.png';
import leavesWebp from '../../../images/calm/leaves.webp';
import markPng from '../../../images/calm/mark-leaf.png';
import markWebp from '../../../images/calm/mark-leaf.webp';
import portraitJpg from '../../../images/calm/portrait.jpg';
import portraitWebp from '../../../images/calm/portrait.webp';
import whyPng from '../../../images/calm/why-heart.png';
import whyWebp from '../../../images/calm/why-heart.webp';

export type CalmArtSlot =
    | 'hero'
    | 'why'
    | 'portrait'
    | 'about'
    | 'contact'
    | 'mark'
    | 'leaves';

// The photos fall back to JPEG, not PNG: `png` is only the <img> fallback URL,
// and a lossless photo would weigh ten times as much.
export const CALM_ART: Partial<Record<CalmArtSlot, LandingImage>> = {
    hero: { webp: heroWebp, png: heroPng, width: 1100, height: 698 },
    why: { webp: whyWebp, png: whyPng, width: 720, height: 526 },
    portrait: { webp: portraitWebp, png: portraitJpg, width: 320, height: 320 },
    about: { webp: aboutWebp, png: aboutJpg, width: 880, height: 640 },
    contact: { webp: contactWebp, png: contactPng, width: 900, height: 594 },
    mark: { webp: markWebp, png: markPng, width: 128, height: 117 },
    leaves: { webp: leavesWebp, png: leavesPng, width: 360, height: 292 },
};
