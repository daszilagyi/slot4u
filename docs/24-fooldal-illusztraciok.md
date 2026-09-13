# slot4u — Főoldal illusztrációk elhelyezése (a látványterv szerint)

> Státusz: ÉRVÉNYES, 2026-09-13. Kiegészíti a `docs/21`-et (szekciók) és a `docs/23`-at (hero-lajhár animáció). A hero-lajhárt NEM érinti — az a docs/23.
> Referencia-kép: `docs/design/foldal/latvanyterv-fooldal.png` (a Claude Design-os teljes oldal). A pozíciókat ehhez kell mérni.
> Kapcsolódó Linear: SLO-229 (a szekciók már megvannak, ezekbe kerülnek a képek), SLO-231 (hero), és a jelen feladat issue-ja.

> ⚠️ **Megvalósítva (SLO-236, 2026-09-13) — eltérésekkel, mérés alapján:**
> 1. **Útvonal: `resources/images/sloth/` (Vite-bundle), NEM `public/brand/sloth/`.** Az apex domainen a `public/`
>    fájljai prodon 404-et adnak (SLO-233) — ugyanaz az ok, mint a docs/23-nál. Az URL-ek és a méretek a
>    `resources/js/components/landing/landingArt.ts`-ben élnek; README: `resources/images/sloth/README.md`.
> 2. **Lang-kulcsok:** a meglévő `welcome.tools.note_*`, `welcome.calendar.cheer`, `welcome.cta_band.note_*`
>    (az SLO-229 óta léteznek), nem `marketing.*`.
> 3. **Az Eszközök kézírása a kép ALATT**, nem a jobb alsó sarkában: ott a sötétkék fotelre esett, olvashatatlanul.
> 4. **A szerveroldali `MarketingArt` slot-mechanizmus (SLO-229) megszűnt** — minden illusztráció a bundle része.
> 5. **Méret:** a hat WebP együtt ~406 KB (a §3 célja < 400 KB); a `sloth-armchair.webp` 148 KB, ez a legnagyobb.
> 6. A referencia-kép (`docs/design/foldal/latvanyterv-fooldal.png`) nincs a repóban — a pozíciók a §2 leírása szerint készültek.
> 7. **A `sloth-peek` átlógása 53 %, nem 35 %** (2026-09-13, élesben észrevéve): a képen az alkarok a 235–300. képsorban futnak (502-ből); a szekció élének az alkarok tetején kell futnia, hogy a lajhár a peremre támaszkodjon. 35 %-nál a perem az alkarok alá esett, és a lajhár a perem fölött lebegett.

---

## 0. Egy bekezdésben

A látványterven a szekciókban a lajhár-kabala további pózai és két egyszerű illusztráció adják a "barátságos SaaS" hangulatot. Az assetek elkészültek (Daniel, ChatGPT; tisztítva, méretezve, WebP-vel), mind **átlátszó PNG/WebP**, **dekoratív** elemek: nem hordoznak információt, ezért `alt=""`, és a szöveg-elrendezést nem törhetik. Hat kép, öt hely. Minden más, ami a látványterven van (statisztika-sáv, vélemények, footer social-ikonok) **nem** része ennek — lásd §4.

---

## 1. Assetek — `public/brand/sloth/` (mellé a docs/23 rétegeinek)

| Fájl (`.png` + `.webp`) | Mi van rajta | Méret (px) | WebP | Hova |
|---|---|---|---|---|
| `illus-laptop-profile` | laptop, képernyőn profilkártya | 800 × 448 | 16 KB | Hogyan működik – 1. lépés |
| `illus-calendar-gear` | naptár + fogaskerék | 800 × 591 | 35 KB | Hogyan működik – 2. lépés |
| `sloth-cheer` | ujjongó lajhár, csillagokkal | 800 × 576 | 66 KB | Hogyan működik – 3. lépés |
| `sloth-armchair` | fotelben laptopozó lajhár, növény, "GOOD SLOTS" bögre | 1100 × 1058 | 142 KB | Eszközök szekció, bal oszlop |
| `sloth-peek` | a szekció alsó széle mögül bekukucskáló lajhár | 800 × 502 | 47 KB | Naptár-bemutató, jobb alsó sarok |
| `sloth-beanbag` | babzsákban laptopozó, kacsintó lajhár | 900 × 662 | 63 KB | Záró CTA, bal oldal |

**Jogtisztaság:** saját generálású assetek. A laptopokról a gyártói logó **el lett távolítva** (a `sloth-armchair` és `sloth-beanbag` esetében — a régi, magyar nevű forrásfájlok logóval vannak, azok **nem** kerülhetnek a repóba). A bögre "GOOD SLOTS" felirata képbe égetett angol szöveg — dekoratív, márkajáték, marad (nem UI-szöveg, i18n nem vonatkozik rá).

**Formátum a kódban:** minden kép `<picture>` WebP + PNG fallback, `width`/`height` attribútummal (CLS), `loading="lazy"` és `decoding="async"` (mind a fold alatt van), `alt=""` + a wrapper `aria-hidden="true"`. Nem inline-oljuk, nem base64.

---

## 2. Elhelyezés szekciónként (desktop 1440 / mobil 390)

A szekciónevek az SLO-229 sorrendje szerint: Nav · Hero · Célcsoport-sáv · **Hogyan működik** · **Eszközök** · Rugalmas foglalási módok · **Naptár-bemutató** · Árazás · Próbáld ki élőben · GYIK · **Záró CTA** · Footer.

### 2.1 Hogyan működik (3 lépés) — kártyánként egy kép

A látványterven mindhárom kártya alsó felében egy illusztráció ül, a szöveg alatt, középre igazítva, világos háttéren.

- 1. Regisztrálsz → `illus-laptop-profile` · 2. Beállítod → `illus-calendar-gear` · 3. Jönnek a foglalások → `sloth-cheer`
- Képdoboz: a kártya alján, `max-height: 150px` desktopon (`120px` mobilon), `object-fit: contain`, vízszintesen középen; a kártya magassága a három kártyán **egyforma** maradjon (`grid` + `items-stretch`, a kép a kártya aljához `mt-auto`-val).
- A 3. kártya lajhárja a terven kicsit "kilóg" a kártya jobb alsó sarkán — ezt **ne** csináld meg, maradjon a dobozban (mobilon szétesne).
- Animáció: a kártyák meglévő scroll-into-view fade-up-jával együtt jelenik meg, külön mozgás nincs.

### 2.2 Eszközök ("Professzionális eszközök a vállalkozásodhoz") — bal oszlop

- `sloth-armchair` a bal oszlopban, a jobb oldali funkciólistával egy magasságban, **függőlegesen középre** igazítva. Szélesség desktopon ~520 px (`max-w-[520px] w-full`), a kép alja a szekció alsó paddingjához ér.
- A terven a kép alatt kézírásos felirat: *"Kevesebb admin. Több szabadidő."* + szív. Ez **HTML-szöveg** (Caveat font, a marketing tokenekből, sárga/navy), lang-kulcs: `marketing.tools.handwritten` — NEM kerül a képbe. Pozíció: a kép jobb alsó sarkához közel, enyhén elforgatva (`-rotate-6`).
- Mobil: a kép a lista **fölé** kerül, `max-w-[320px]`, középen; a kézírásos felirat alatta középen.

### 2.3 Naptár-bemutató ("Átlátható naptár, nyugodt mindennapok") — bekukucskáló lajhár

- `sloth-peek` a szekció **jobb alsó sarkában**, úgy, hogy a szekció alsó éle mögül "kukucskál": a kép alsó ~35 %-a a következő szekcióba lóg (`absolute bottom-0 right-[4%] translate-y-[35%]`), a szekció `relative`, **`overflow: visible`**, és a képnek `z-index`-e a következő szekció fölött legyen (a következő szekció háttere a lajhár mögé kerül — ha a következő szekció sötét, a kontraszt jó; ha világos, akkor is működik).
- Szélesség desktopon ~260 px. A terven mellette egy "Szép munka!" buborék van — ez opcionális HTML-elem (`marketing.calendar.bubble`), a képhez képest bal-felül, csak desktopon.
- Animáció: `useInView`-ra 400 ms alatt alulról felcsúszik (`y: 40 → 0`, opacity), egyszer; reduced-motion esetén azonnal a helyén.
- Mobil: a lajhár **elrejtve** (`hidden lg:block`) — 390 px-en nincs hely, és a szekcióátlógás mobilon rossz.

### 2.4 Záró CTA ("Készen állsz…") — babzsákos lajhár

- `sloth-beanbag` a szekció bal oldalán, a cím mellett, függőlegesen középen, ~300 px széles desktopon; a szekció világos-sárga háttere (a design tokenje) mögötte marad.
- A terv jobb szélén kézírásos *"Több idő, Rád is vár"* + szív: HTML-szöveg Caveat fontból, `marketing.cta.handwritten`, csak desktopon (`hidden lg:block`).
- Mobil: a lajhár a cím **fölött**, `max-w-[240px]`, középen.
- Animáció: nincs (a záró CTA a hero-val azonos "lebegés" loopot kapná a `docs/21` szerint — ezt itt **kihagyjuk**, elég a hero-ban egy mozgó lajhár; egy oldalon két lebegő kabala már sok).

### 2.5 Ami a képek környékén szövegként a tervben megjelenik

A terv kézírásos "matricái" (*"Egyszerű, mint 1-2-3"* a Hogyan működik címe mellett, a fenti két kézírásos felirat) mind **HTML-szöveg** Caveat fonttal és lang-kulccsal — a design "kézírás" hatását a font adja, nem kép. Ha a Caveat még nincs a marketing tokenekben (SLO-229 hozta be), ellenőrizd, hogy self-hosted `@fontsource` a docs/19 szerint.

---

## 3. Ellenőrzés (DoD ehhez a feladathoz)

- [ ] Playwright screenshot 1440 és 390 px-en, egymás mellé a `latvanyterv-fooldal.png` megfelelő szekciójával — a pozíció és a méret a terv szerint, a mobil kivételek (§2.3, §2.4) szerint.
- [ ] Egyik kép sem fedi a szöveget vagy a gombokat semelyik töréspontnál (1440 / 1024 / 768 / 390).
- [ ] Lighthouse mobil: minden új kép `lazy`, **LCP nem változik** az SLO-231 utáni állapothoz képest; a képek összesen < 400 KB WebP-ben.
- [ ] Nincs CLS: minden `<img>`-nek `width`/`height` van.
- [ ] `alt=""` mindenhol, a wrapper `aria-hidden`; a kézírásos szövegek lang-kulcsból jönnek (i18n reviewer zöld).
- [ ] A `sloth-peek` átlógása nem hoz létre vízszintes görgetést (`overflow-x` a `body`-n ellenőrizve).
- [ ] `public/brand/README.md` frissítve az új fájlokkal és a "hol jelenik meg" táblázattal.
- [ ] `WelcomeTest`, `VerticalLandingTest`, SSR smoke zöld; lint + build tiszta.

---

## 4. Ami a látványterven van, de NEM készül el

- **"Mérhető siker" statisztika-sáv** (500+, 120 000+, 40 %, 98 %): kitalált számok — nem építjük meg (ugyanaz az elv, mint a vélemény-szekciónál, SLO-205 döntés). A `sloth-peek` ettől függetlenül a naptár-szekció aljára kerül.
- **"Ezt mondják rólunk" vélemények**: kimarad (SLO-205 döntés), a portrék sem kellenek.
- **Footer social-ikonok, Blog/Karrier/Rólunk/Tudástár linkek**: kimaradnak (SLO-229 döntés).
- **Célcsoport-sáv ikonjai**: Lucide vonalas ikonok, nem képek (a docs/21 szerint).

---

## 5. Így add oda Claude Code-nak

```
Olvasd el a docs/24-fooldal-illusztraciok.md-t és nézd meg a docs/design/foldal/latvanyterv-fooldal.png-t.
Az assetek a repó temp/sloth/ mappájában vannak (illus-laptop-profile, illus-calendar-gear, sloth-cheer,
sloth-armchair, sloth-peek, sloth-beanbag — png+webp): git mv-vel tedd őket a public/brand/sloth/ alá,
majd helyezd el őket a docs/24 §2 szerint a meglévő SLO-229-es szekciókban. <picture> WebP+PNG,
width/height, lazy, alt="" + aria-hidden. A kézírásos feliratok HTML-szöveg Caveat fonttal, lang-kulcsból.
A docs/24 §4 elemeit NE építsd meg. A §3 minden pontját igazold a PR-leírásban.
```
