# Calm landing template art (SLO-238, docs/25)

Imported through Vite by `resources/js/components/tenant-landing/calmArt.ts` — not
`public/`, for the same reason as `resources/images/sloth/` (SLO-233).

| File (`.webp` + fallback) | What | Size | Slot / where |
|---|---|---|---|
| `hero-session` (`.png`) | sloth therapist in an armchair, fox on the couch | 1100 × 698 | `hero` — right of the heading, on the sand arch |
| `why-heart` (`.png`) | sloth hugging a heart in an armchair | 720 × 526 | `why` — the page draws the speech bubble from the tenant's quote |
| `portrait` (`.jpg`) | therapist portrait, square crop | 320 × 320 | `portrait` — the round photo in About |
| `about-room` (`.jpg`) | green chaise in a practice room | 880 × 640 | `about` — right of About (desktop) |
| `contact-room` (`.png`) | armchair, bookshelf, "Jobb önmagadért." frame | 900 × 594 | `contact` — between the contact card and the question card |
| `mark-leaf` (`.png`) | leaf logo mark | 128 × 117 | `mark` — header and footer, beside the brand title |
| `leaves` (`.png`) | leaf cluster | 360 × 292 | `leaves` — corner of the question card (xl only) |

All decorative: `alt=""`, `aria-hidden`, real `width`/`height`; only the hero loads eagerly.

## Sources and processing

- The illustrations were generated for the Lélekút design (Daniel, 2026-09-13).
- The photos are from Pexels, both by **MART PRODUCTION**, under the
  [Pexels licence](https://www.pexels.com/license/) (free to use, attribution not
  required):
  - `portrait` — https://www.pexels.com/photo/7699304/
  - `about-room` — https://www.pexels.com/photo/7699458/ (cropped to the empty chaise)

  ⚠️ The licence forbids implying that an identifiable person endorses a product.
  The portrait stands in for a **fictional** practitioner on a demo tenant (behind
  the DEMO banner). Do not reuse it as the face of a real tenant's staff.
- The sloth-with-heart source had its speech bubble baked in; it was removed
  (connected components right of the sloth) so the text stays tenant content.
- The room source had a fake transparency checkerboard painted into it; it was
  removed by flood fill of neutral pixels from the edges, plus enclosed checker
  pockets and the grey halo, then a 1 px alpha erode.
- Resized with sharp; WebP q88 (photos q80), PNG palette q92, JPEG mozjpeg q82.
- Text inside the artwork ("Jobb önmagadért.") is part of the picture, not UI text.
