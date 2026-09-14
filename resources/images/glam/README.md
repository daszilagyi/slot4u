# Glam landing template art (SLO-241, docs/26)

Imported through Vite by `resources/js/components/tenant-landing/glamArt.ts` — not
`public/`, for the same reason as `resources/images/sloth/` (SLO-233).

| File (`.webp` + fallback) | What | Size | Slot / where |
|---|---|---|---|
| `hero-sloth` (`.png`) | sloth stylist cutting the sheep's hair, GlamZone cape | 1100 × 848 | fixed `hero` — bottom-left of the hero card |
| `sloth-phone` (`.png`) | sloth holding a phone | 242 × 251 | fixed `phone` — left of the three steps (xl) |
| `sloth-dryer` (`.png`) | sloth with a hair dryer | 265 × 282 | fixed `dryer` — left of the final CTA (xl) |
| `mark-flower` (`.png`) | flower logo mark | 151 × 154 | fixed `mark` — header and footer |
| `salon` (`.jpg`) | stylist at work, salon floor | 1100 × 760 | fixed `salon` — left half of the contact band |
| `cat-hair`, `cat-beauty`, `cat-nails`, `cat-feet` (`.jpg`) | category photos | 720 × 520 | named — `landing.categories[].photo` |
| `svc-cut`, `svc-colour`, `svc-facial`, `svc-gel` (`.jpg`) | service photos | 640 × 440 | named — `landing.featured[].photo` |
| `staff-1`, `staff-2`, `staff-3` (`.jpg`) | portraits | 240 × 340 | named — `landing.team[].photo` |

All decorative: `alt=""`, `aria-hidden`, real `width`/`height`; only the hero loads eagerly.

## Sources and processing

- The sloths and the flower mark were generated for the GlamZone design (Daniel,
  2026-09-14): `hero-sloth` from the standalone cut-out, the others cropped from the
  character sheet. Their alpha is real, but the generator left it at 252–254 on
  "opaque" pixels, which reads as a faint haze on a dark page; it was snapped to 255
  wherever it was 240 or above. The PNG fallbacks are palette-quantised.
- The photos are from Pexels, under the [Pexels licence](https://www.pexels.com/license/)
  (free to use, attribution not required):
  - `cat-hair` — sergeymakashin, https://www.pexels.com/photo/5368632/
  - `cat-beauty`, `svc-facial` — José Antonio Otegui Auzmendi, https://www.pexels.com/photo/34930126/
  - `cat-feet` — José Antonio Otegui Auzmendi, https://www.pexels.com/photo/34930123/
  - `cat-nails`, `svc-gel` — tkirkgoz, https://www.pexels.com/photo/14018564/
  - `svc-cut` — cottonbro studio, https://www.pexels.com/photo/3993465/
  - `svc-colour` — Anthony Shkraba, https://www.pexels.com/photo/6599036/
  - `salon` — cottonbro studio, https://www.pexels.com/photo/3993320/
  - `staff-1` — Mihaela Claudia Puscas, https://www.pexels.com/photo/33175451/
  - `staff-2` — Mikhail Nilov, https://www.pexels.com/photo/6739931/
  - `staff-3` — Marjorie Matias, https://www.pexels.com/photo/16999280/

  ⚠️ The licence forbids implying that an identifiable person endorses a product.
  The portraits stand in for **fictional** stylists on a demo tenant (behind the DEMO
  banner, with the demo note in the footer). Do not reuse them as the faces of a real
  tenant's staff.
- Not used: `pexels-cottonbro-3992875` and `-3993326` (the same salon as `salon`) and
  the two design mock-ups.
- The crop script is `temp/imgtool/glambuild.js` (not in the repo).
