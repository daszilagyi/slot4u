# Home page sloth art (docs/23, docs/24)

Two sets live here: the hero sloth's animation layers (docs/23) and the section
illustrations below the hero (docs/24). Every file is imported through Vite — see
the note at the bottom on why not `public/`.

## Hero layers — `HeroSloth.tsx` (docs/23)

| File | What | Where it shows |
|---|---|---|
| `sloth-full.{webp,png}` | cape + body, composed | server-rendered HTML, reduced motion, no JS — and the preloaded LCP image |
| `cape.{webp,png}` | the cape alone, behind the body | animated layer (sway from the shoulder) |
| `body.{webp,png}` | body and head, eyes open, no cape | animated layer |
| `eyes-closed.{webp,png}` | the viewer's-left eye closed | animated layer (the wink) |

⚠️ **Every file shares one 1024 × 920 canvas, and that is what makes the layers
line up with no offsets. Do not crop, trim or resize one of them on its own** —
it would drift out of place. Re-export all of them from the master instead.

## Section illustrations — `landingArt.ts` (docs/24)

| File (`.png` + `.webp`) | What | Size | Where it shows |
|---|---|---|---|
| `illus-laptop-profile` | laptop with a profile card | 800 × 448 | How it works, step 1 |
| `illus-calendar-gear` | calendar and cog | 800 × 591 | How it works, step 2 |
| `sloth-cheer` | cheering sloth with stars | 800 × 576 | How it works, step 3 |
| `sloth-armchair` | sloth in an armchair with a laptop, plant, GOOD SLOTS mug | 1100 × 1058 | Tools section, left column |
| `sloth-peek` | sloth peeking over an edge | 800 × 502 | Calendar section, bottom right, hanging into the next section (desktop only) |
| `sloth-beanbag` | winking sloth in a beanbag with a laptop | 900 × 662 | Closing CTA, left of the heading |

All decorative: `alt=""`, wrapper `aria-hidden`, `loading="lazy"`, real
`width`/`height` on the tag. ⚠️ The laptops carry no manufacturer logo — the
Hungarian-named source files in `temp/sloth/` still do, and must never be
committed. The mug's "GOOD SLOTS" is part of the artwork, not UI text.

## Masters

- Hero master: `docs/design/foldal/hero_lajhar.psd` (Daniel's Photoshop file; not in the repo).
- Cape pivot: canvas point (490, 360) → `transform-origin: 47.9% 39.1%`.
- WebP first, PNG fallback, both with alpha.

Why here and not in `public/brand/`: files under `public/` are not served on the
production apex domain (its docroot is the `~/public_html` bridge, which exposes
only `build/` and `storage/`). Imported through Vite, these land in
`/build/assets/` with a content hash — served, and cache-busting on their own.
