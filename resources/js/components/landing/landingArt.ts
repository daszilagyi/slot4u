/*
 * The home page illustrations below the hero (SLO-236, docs/24 §1).
 *
 * ⚠️ Bundled through Vite from `resources/images/sloth/`, not served from
 * `public/brand/sloth/` as docs/24 wrote — on the production apex domain nothing
 * under `public/` but `build/` and `storage/` is reachable (SLO-233), the same
 * reason the hero sloth lives here (docs/23). Imported, each file lands in
 * `/build/assets/` with a content hash.
 *
 * Width and height travel with the URLs: every <img> gets them, so the box is
 * reserved before the file arrives and nothing shifts (docs/24 §3, CLS).
 */
import armchairPng from '../../../images/sloth/sloth-armchair.png';
import armchairWebp from '../../../images/sloth/sloth-armchair.webp';
import beanbagPng from '../../../images/sloth/sloth-beanbag.png';
import beanbagWebp from '../../../images/sloth/sloth-beanbag.webp';
import calendarPng from '../../../images/sloth/illus-calendar-gear.png';
import calendarWebp from '../../../images/sloth/illus-calendar-gear.webp';
import cheerPng from '../../../images/sloth/sloth-cheer.png';
import cheerWebp from '../../../images/sloth/sloth-cheer.webp';
import laptopPng from '../../../images/sloth/illus-laptop-profile.png';
import laptopWebp from '../../../images/sloth/illus-laptop-profile.webp';
import peekPng from '../../../images/sloth/sloth-peek.png';
import peekWebp from '../../../images/sloth/sloth-peek.webp';

export type LandingImage = {
    webp: string;
    png: string;
    width: number;
    height: number;
};

export const ILLUS_LAPTOP: LandingImage = {
    webp: laptopWebp,
    png: laptopPng,
    width: 800,
    height: 448,
};
export const ILLUS_CALENDAR: LandingImage = {
    webp: calendarWebp,
    png: calendarPng,
    width: 800,
    height: 591,
};
export const SLOTH_CHEER: LandingImage = {
    webp: cheerWebp,
    png: cheerPng,
    width: 800,
    height: 576,
};
export const SLOTH_ARMCHAIR: LandingImage = {
    webp: armchairWebp,
    png: armchairPng,
    width: 1100,
    height: 1058,
};
export const SLOTH_PEEK: LandingImage = {
    webp: peekWebp,
    png: peekPng,
    width: 800,
    height: 502,
};
export const SLOTH_BEANBAG: LandingImage = {
    webp: beanbagWebp,
    png: beanbagPng,
    width: 900,
    height: 662,
};
