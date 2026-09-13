/*
 * The calm template's illustrations (SLO-238, docs/25).
 *
 * The artwork lives in the Claude Design project and could not be downloaded
 * from there (its files exceed the export limit), so it arrives separately.
 * Until a slot has a file, the section draws its soft placeholder shape instead
 * — the layout never waits for, or breaks without, a picture.
 *
 * To add one: put the file in `resources/images/calm/`, import it here and add
 * the entry. Bundled through Vite, like every landing image (SLO-233).
 */
import type { LandingImage } from '@/components/landing/landingArt';

export type CalmArtSlot = 'hero' | 'why' | 'portrait' | 'about' | 'contact';

export const CALM_ART: Partial<Record<CalmArtSlot, LandingImage>> = {};
