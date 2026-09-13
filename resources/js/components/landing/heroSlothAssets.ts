/*
 * The hero sloth's layers (SLO-232, docs/23 §2), in one place so the component
 * and the page's <head> preload read the same URLs.
 *
 * ⚠️ Bundled through Vite, not served from `public/brand/sloth/` as docs/23 §2
 * wrote. On production the apex docroot is the `~/public_html` bridge, which
 * exposes only `build/` and `storage/` from the app's `public/` — a file under
 * `public/brand/` answers 404 there (measured: the existing `/img/og-image.png`
 * does). Imported, they land in `/build/assets/` with a content hash, which is
 * served and busts its own cache.
 *
 * Every file shares one 1024 × 920 canvas, so the layers stack with no offsets.
 * Do not re-crop them: they would stop lining up.
 */
import bodyPng from '../../../images/sloth/body.png';
import bodyWebp from '../../../images/sloth/body.webp';
import capePng from '../../../images/sloth/cape.png';
import capeWebp from '../../../images/sloth/cape.webp';
import eyesPng from '../../../images/sloth/eyes-closed.png';
import eyesWebp from '../../../images/sloth/eyes-closed.webp';
import fullPng from '../../../images/sloth/sloth-full.png';
import fullWebp from '../../../images/sloth/sloth-full.webp';

export type SlothImage = { webp: string; png: string };

/** The static composite — what the server renders, and all a reduced-motion visitor gets. */
export const SLOTH_FULL: SlothImage = { webp: fullWebp, png: fullPng };
export const SLOTH_CAPE: SlothImage = { webp: capeWebp, png: capePng };
export const SLOTH_BODY: SlothImage = { webp: bodyWebp, png: bodyPng };
export const SLOTH_EYES_CLOSED: SlothImage = { webp: eyesWebp, png: eyesPng };
