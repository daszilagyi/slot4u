# slot4u — Hero-lajhár animáció (lebegés + köpeny + kacsintás)

> Státusz: ÉRVÉNYES, 2026-09-13. Kiegészíti a `docs/21` §2 1. sorát (hero) és felülírja annak `hero-sloth-flying.svg` asset-előírását.
> Hatály: a `slot4u.hu` főoldal hero szekciója (`MarketingLayout`), később a `/autoszerviz` és a további vertikális landingek ugyanezt a komponenst használják.
> Kapcsolódó Linear: SLO-229 (főoldal a Claude Design alapján — erre épül), SLO-202 (brand-assetek — a hero-lajhárt EZ a doksi váltja ki), és a jelen feladat issue-ja.

> ⚠️ **Megvalósítva (SLO-232, 2026-09-13) — három eltéréssel, mérés alapján:**
> 1. **Útvonal: `resources/images/sloth/`, NEM `public/brand/sloth/`.** Prodon az apex docroot a `~/public_html`
>    bridge, amely az app `public/` mappájából csak a `build/`-et és a `storage/`-t teszi elérhetővé
>    (mérve: a már meglévő `https://slot4u.hu/img/og-image.png` **404**). A rétegeket ezért a Vite bundle-özi
>    `/build/assets/` alá, tartalom-hash-sel — ez prodon kiszolgálódik, és magától cache-bustol. Külön
>    issue: SLO-233 (a `public/img` és a `public/brand` slotok prodon 404-et adnak).
> 2. **A lang-kulcs `welcome.hero.sloth_alt`**, nem `marketing.hero.sloth_alt` — a hero minden szövege a
>    `welcome.hero` alatt él (SLO-229).
> 3. **Mobilon a lajhár a kártya FÖLÖTT repül**, nem mögötte: 390 px-en a kártya különben teljesen
>    eltakarná. Desktopon a design szerinti átfedés marad.
>
> Komponens: `resources/js/components/landing/HeroSloth.tsx`, URL-ek: `heroSlothAssets.ts`.

---

## 0. Egy bekezdésben

A hero jobb oldalán a repülő hős-lajhár nem statikus kép, hanem **három egymásra tett PNG/WebP réteg** (köpeny → test → csukott szem), amelyeket **Framer Motion** mozgat: az egész karakter lassan lebeg, a köpeny a vállponton enyhén leng, a lajhár **véletlen időközönként kacsint**. Nincs integetés, nincs Rive, nincs videó, nincs SVG-vektorizálás. `prefers-reduced-motion` esetén és SSR-ben az összerakott statikus kép látszik. A cél bizalomépítő "él a rendszer" hatás, **visszafogottan**: a hero 3 másodperc után nyugodt, egyszerre egy mozgás történik.

---

## 1. Döntések (Daniel, 2026-09-10 → 09-13)

| Döntés | Miért |
|---|---|
| **PNG/WebP rétegek, nem SVG** | A kabala rajzstílusa (lágy árnyalatok, szőr) csak minőségromlással vektorizálható. A `docs/21` §4.3 és az SLO-202 `sloth-flying.svg` sora **elavult** erre a pózra. A `sloth-laptop` és `sloth-wave` póz továbbra is az SLO-202-ben marad. |
| **Framer Motion, nem Rive** | A stack már tartalmazza, nulla új függőség, SSR-biztos. A Rive szövetszerűbb köpenyt adna, de plusz eszköz + ~100 KB wasm; ha később kell, ugyanezekből a rétegekből átemelhető. |
| **Csak lebegés + köpeny + kacsintás** | Az integetés (külön kar-réteg) elhagyva — túl sok munka a hozzáadott értékhez képest. |
| **Nem hero-háttérvideó** | Loop-ugrás, karakter-drift az AI-videóban, nincs alfa, 3–8 MB, nem irányítható. |
| **A körülötte lévő elemek HTML/SVG** | A slot-kártya, a "Foglalás sikeres!" matrica, a kézírásos felirat, a hullám/felhő: valódi komponensek (i18n!), a lajhár **mögé/elé** rétegezve, nem a képbe égetve. Ezek az SLO-229-ben már elkészültek — ez a doksi csak a lajhárt cseréli. |

---

## 2. Assetek — `resources/images/sloth/` (eredetileg `public/brand/sloth/`, l. a fejléc eltérés-dobozát)

> **Átmeneti hely:** Daniel a fájlokat a repó `temp/sloth/` mappájába másolta be. **Az implementáció első lépése:** `git mv temp/sloth/*.png temp/sloth/*.webp public/brand/sloth/` (a `preview-on-navy.png` nem kell a bundle-be — törölhető), majd a `temp/` mappa üresen törlendő. A kódban és ebben a doksiban **mindenhol a végleges `public/brand/sloth/` útvonal** szerepel; a `temp/sloth/`-ra hivatkozás a kódban tilos.

Minden fájl **ugyanazon az 1024 × 920 px vásznon**, azonos pozícióban, valódi alfa-csatornával. Ezért egyszerűen `position:absolute; inset:0`-val egymásra téve pixelre illeszkednek — **nem kell** egyedi offset egyik rétegnek sem.

| Fájl | Tartalom | Tartalom bbox (px, a 1024×920 vásznon) | Méret |
|---|---|---|---|
| `body.png` / `.webp` | test + fej **nyitott** szemmel, **köpeny nélkül** | x 182–1006 · y 40–862 | 483 KB / 67 KB |
| `cape.png` / `.webp` | csak a köpeny (a test mögé kerül) | x 12–500 · y 252–658 | 123 KB / 21 KB |
| `eyes-closed.png` / `.webp` | csak a bal (néző felőli) szem csukva, lágy szélű folt; **a test fölé** | x 503–719 · y 173–378 | 55 KB / 12 KB |
| `sloth-full.png` / `.webp` | köpeny + test összerakva — **statikus placeholder** (SSR, reduced-motion, JS nélkül) | x 12–1006 · y 40–862 | 565 KB / 79 KB |
| `preview-on-navy.png` | csak ellenőrzéshez, nem kerül a bundle-be | – | – |

**Rétegsorrend (alulról felfelé):** `cape` → `body` → `eyes-closed`.

**Köpeny forgáspontja** (a vállnál, ahol a köpeny a nyak mögé fut): a vászon **(490, 360) px** pontja → `transform-origin: 47.9% 39.1%`. A köpeny jobb széle ~500 px-nél a test mögé bújik, tehát ±3° forgatásnál sem látszik ki a vége.

**Formátum:** `<picture>`-ben WebP elsőnek, PNG fallback. A WebP `quality 88`, alfával — vizuálisan azonos. Összesen a három animált réteg WebP-ben **~100 KB**.

**Megjelenítési méret:** a `docs/21` szerint ~420 px széles desktopon; a vászon 1024 × 920, tehát az `aspect-ratio: 1024 / 920` dobozban a karakter ~410 px széles lesz, ami pont jó. Mobilon (390) a hero-ban ~260 px.

**Forrás:** ChatGPT képgenerátor (Daniel, 2026-09-10), rétegbontás Photoshopban (`docs/design/foldal/hero_lajhar.psd` a mester — innen bármikor újraexportálható), tisztítás/vágás/WebP a Cowork-munkamenetben. Jogtiszta: saját készítésű asset.

---

## 3. Komponens-spec — `HeroSloth`

**Hely:** `resources/js/Components/marketing/HeroSloth.tsx` (a marketing komponensek mellé, ahova az SLO-229 a hero elemeit tette — ha ott más a mappa-konvenció, azt kövesd).

**Props:**

```ts
type HeroSlothProps = {
  className?: string;   // méret/pozíció kívülről (pl. "w-[420px] max-w-full")
  priority?: boolean;   // true a hero-ban: a placeholder kép fetchpriority="high"
};
```

**Felépítés:**

```
<div class="relative aspect-[1024/920] {className}" aria-hidden="false">
  <picture>  ← statikus placeholder, SSR-ben ez a teljes kimenet
    <source type="image/webp" srcset="/brand/sloth/sloth-full.webp">
    <img src="/brand/sloth/sloth-full.png" alt={t('marketing.hero.sloth_alt')}
         width="1024" height="920" decoding="async"
         fetchpriority={priority ? 'high' : undefined}
         class="absolute inset-0 w-full h-full" />
  </picture>

  {mounted && !reducedMotion && (      ← CSAK kliens oldalon, hydration után
    <motion.div class="absolute inset-0" animate={float}>   ← lebegés az egész csoporton
      <motion.img src=cape  class="absolute inset-0" style={{transformOrigin:'47.9% 39.1%'}} animate={capeSway} alt="" />
      <img src=body class="absolute inset-0" alt="" />
      <motion.img src=eyes-closed class="absolute inset-0" animate={{opacity: blinking ? 1 : 0}} transition={{duration: 0.06}} alt="" />
    </motion.div>
  )}
</div>
```

A placeholder `<picture>` az animált csoport megjelenésekor `opacity: 0`-ra vált (nem `display:none` — így nem lesz layout-ugrás és az LCP-elem megmarad). A három animált réteg `<img>`-je szintén `<picture>` WebP/PNG párral (vagy a `srcSet` attribútummal), `loading="lazy"` **nélkül** — a hero above-the-fold, a lazy csak késleltetné a csere pillanatát.

**Animációk (Framer Motion, `docs/21` §2 elveivel):**

| Mi | Érték | Megjegyzés |
|---|---|---|
| Belépés (egyszer) | `x: 40 → 0, y: 24 → 0, opacity 0 → 1`, 600 ms, easing `[.2,.8,.2,1]` | a `docs/21` "jobb-lentről berepül" — az egész csoporton; SSR placeholder alatt fut, a csere a belépés végén |
| Lebegés (loop) | `y: [0, -6, 0]`, 4 s, `easeInOut`, `repeat: Infinity` | a `docs/21`-ben rögzített ±6 px / 4 s |
| Köpeny lengés (loop) | `rotate: [0, -2.5, 0, 2.5, 0]`, `skewX: [0, 1.5, 0, -1.5, 0]`, 3.2 s, `easeInOut`, `repeat: Infinity`, `delay: 0.4` | a fáziseltolás miatt nem "egyszerre" mozog a testtel — ettől él |
| Kacsintás | csukott szem réteg `opacity 1` **140 ms**-ig, majd vissza | 6–11 s közötti **véletlen** időköz (`5000 + Math.random()*6000` ms); 20 % eséllyel dupla kacsintás (két 140 ms, 120 ms szünettel) |
| Első kacsintás | ~1,8 s a belépés után | hogy a látogató a "megérkezés" után lássa, ne a betöltés közben |

**Nincs:** egérkövetés a lajháron (a glow-parallax a `docs/21`-ben a háttérre vonatkozik, marad ott), hover-reakció, integetés, hang.

**Állapotkezelés és leállítás:**

- `useReducedMotion()` (framer-motion) → `true` esetén **csak** a placeholder renderelődik. Nincs "lassított" változat.
- `mounted` flag `useEffect`-ben → SSR-ben és hydration előtt a placeholder az egyetlen kimenet. **A komponens SSR alatt nem érhet `window`-hoz** (SSR smoke teszt).
- Kacsintás-időzítő: `setTimeout`-lánc `useEffect`-ben, cleanup unmountkor. **Szünetel**, ha a dokumentum rejtett (`visibilitychange`) vagy a hero kiscrollozott (`useInView` framer-motionből, `amount: 0.2`); ilyenkor a loop-animációk is `paused`. Háttérfülön 0 CPU.
- A placeholder és a rétegek `alt` szövege: a placeholder kapja a leíró `alt`-ot (lang fájl), a három réteg `alt=""` és `aria-hidden` — a felolvasó egy képet lásson, ne négyet.

**i18n:** egyetlen új kulcs: `marketing.hero.sloth_alt` (hu: "A slot4u hős-lajhár kabala repül, mögötte egy sikeres foglalás"). Hardcoded string tilos.

**Beépítés:** az SLO-229 hero-jában a `public/brand/` lajhár-helyőrző (amely "csak akkor jelenik meg, ha a fájl létezik") helyére a `<HeroSloth priority className="…" />` kerül; a méretet/pozíciót a design szerinti helyőrző doboz adja. A feltételes "ha létezik a fájl" logika a hero-lajhárnál **megszűnik** (az asset most már mindig ott van); a laptop/integető pózokra megmarad.

---

## 4. Teljesítmény-követelmények

- LCP-elem a `sloth-full.webp` marad (`fetchpriority="high"`, `<link rel="preload" as="image">` a hero oldalon, `imagesrcset` WebP-vel). Az animált rétegek a placeholder után töltődnek, nem blokkolják az LCP-t.
- Nincs CLS: az `aspect-ratio` doboz és a `width/height` attribútum a placeholderen.
- A hero JS-többlete: csak a komponens + a már betöltött framer-motion; **nincs új npm-függőség**.
- Mobilon (390 px) az animáció ugyanaz — a rétegek WebP-ben együtt ~100 KB, ez belefér; ha a Lighthouse mobil LCP > 2,0 s lenne (M10 demó-kritérium), akkor mobilon csak a placeholder marad (`matchMedia('(min-width: 768px)')`), de ezt **csak mérés után** kapcsold be.
- Compositor-barát: kizárólag `transform` és `opacity` animálódik, `will-change` nélkül (a framer-motion kezeli).

---

## 5. Ellenőrzés (Definition of Done ehhez a feladathoz)

- [ ] Playwright screenshot 1440 és 390 px-en: a lajhár a design szerinti helyen és méretben, a köpeny a test **mögött**, nincs kilátszó köpenyvég, nincs fehér haló navy-n.
- [ ] `page.emulateMedia({ reducedMotion: 'reduce' })` → csak a statikus kép renderelődik, nincs `motion` réteg a DOM-ban.
- [ ] SSR smoke: a szerver-oldali HTML tartalmazza a placeholder `<img>`-et a `sloth-full` forrással, és **nem** tartalmazza a réteg-képeket.
- [ ] 30 mp-es megfigyelés: legalább 3 kacsintás történik, eltérő időközökkel; a lebegés és a köpeny nem egy ütemre mozog.
- [ ] Háttérfülre váltva (DevTools → Performance) nincs folyamatos CPU-terhelés; visszaváltva folytatódik.
- [ ] Lighthouse mobil: LCP nem romlik az SLO-229 állapotához képest (mérés a PR-ben dokumentálva).
- [ ] `WelcomeTest`, `VerticalLandingTest`, SSR smoke zöld; `npm run lint && npm run build` tiszta; Pint/Larastan érintetlen (nincs PHP-változás).
- [ ] `public/brand/README.md` frissítve a `sloth/` almappával (mi hol jelenik meg, a PSD helye, tiltás: nem nyúlunk a rétegek vásznához, mert elcsúsznak).
- [ ] `docs/21` §2 1. sorában az asset-oszlop és §4.3 frissítve: "`hero`: PNG/WebP rétegek, lásd docs/23". SLO-202 táblázatából a `sloth-flying.svg` sor törölve/áthúzva.

---

## 6. Így add oda Claude Code-nak (bemásolható prompt)

```
Olvasd el a docs/23-hero-lajhar-animacio.md-t teljes egészében, utána a docs/21 §2 táblázat 1. sorát (hero).
Első lépés: az assetek a repó temp/sloth/ mappájában vannak — mozgasd őket git mv-vel a public/brand/sloth/ alá
(a preview-on-navy.png törölhető), a temp/ mappát töröld. A rétegek vásznát ne módosítsd.
Utána az SLO-229-ben elkészült hero lajhár-helyőrzőjét cseréld le a docs/23 §3 szerinti HeroSloth komponensre:
három PNG/WebP réteg (public/brand/sloth/), Framer Motion lebegés + köpenylengés + véletlen kacsintás,
SSR-ben és prefers-reduced-motion esetén statikus sloth-full placeholder. Nincs új npm-függőség.
A docs/23 §5 minden pontját ellenőrizd le és a PR-leírásban tételesen jelöld, melyik hogyan lett igazolva.
Ha bármi eltér a docs/23 és a kód között, a docs nyer — az eltérést Linear-kommentben jelezd.
```

---

## 7. Ha mégis Rive kellene később (nem MVP)

Ugyanezek a rétegek importálhatók a Rive-ba: a `cape` 3–4 csontból álló lánc + mesh (szövetszerű hullám), a szemhéj skálázás, state machine `Idle / Wink`. Runtime: `@rive-app/react-canvas`, `.riv` ~50–100 KB. A `HeroSloth` API-ja változatlan maradhat, csak a belseje cserélődik. Ezt akkor érdemes elővenni, ha a köpeny mozgása a Framer Motion-ös forgatással "lapos"-nak hat a valós oldalon.
