# Hero sloth layers (SLO-232, docs/23)

The flying superhero sloth in the home page hero, as stacked layers animated by
`resources/js/components/landing/HeroSloth.tsx`.

| File | What | Where it shows |
|---|---|---|
| `sloth-full.{webp,png}` | cape + body, composed | server-rendered HTML, reduced motion, no JS — and the preloaded LCP image |
| `cape.{webp,png}` | the cape alone, behind the body | animated layer (sway from the shoulder) |
| `body.{webp,png}` | body and head, eyes open, no cape | animated layer |
| `eyes-closed.{webp,png}` | the viewer's-left eye closed | animated layer (the wink) |

⚠️ **Every file shares one 1024 × 920 canvas, and that is what makes the layers
line up with no offsets. Do not crop, trim or resize one of them on its own** —
it would drift out of place. Re-export all of them from the master instead.

- Master: `docs/design/foldal/hero_lajhar.psd` (Daniel's Photoshop file; not in the repo).
- Cape pivot: canvas point (490, 360) → `transform-origin: 47.9% 39.1%`.
- WebP first, PNG fallback, both with alpha.

Why here and not in `public/brand/`: files under `public/` are not served on the
production apex domain (its docroot is the `~/public_html` bridge, which exposes
only `build/` and `storage/`). Imported through Vite, these land in
`/build/assets/` with a content hash — served, and cache-busting on their own.
