# slot4u — főoldal design-terv (arculat, képek, animációk, Claude Design → Claude Code átadás)

> Státusz: ÉRVÉNYES, 2026-09-03. Felülírja a korábbi teal/violet arculati irányt.
> ⚠️ **Stack-korrekció (2026-09-06):** ez a doksi eredetileg Astrót és `tailwind.config`-ot
> feltételezett. A valóság: **Inertia v3 + React 19 SSR** (CLAUDE.md — egy kódbázis, nincs külön
> frontend) és **Tailwind CSS 4**, ahol nincs `tailwind.config`: a tokenek a `resources/css/app.css`
> **`@theme inline`** blokkjában élnek. A 2. és 4. fejezet ennek megfelelően javítva.
> Élő referencia-lap (paletta, tipó, komponensek, Tailwind tokenek): https://claude.ai/code/artifact/7ee3cffb-6e55-48e5-8427-126af5c970c8
> Hatály: a slot4u saját felületei (főoldal, árazás, regisztráció, admin). A demo tenantok landing oldalai külön palettát kaphatnak.

---

## 1. Arculati tokenek (rövid)

| Szerep | Érték | Használat |
|---|---|---|
| navy (alap) | `#0D1B2A` | hero + footer háttér, elsődleges gomb világos felületen |
| brand (kék) | `#1B4F72` | linkek, ikonok, aktív nav |
| brand-100 / 200 | `#E6F0F8` / `#C9DCEC` | ikon-háttér, badge, hover, kijelölt nap |
| accent (sárga) | `#F4B942` | **1 CTA / képernyő**, kiemelt szó, kiválasztott slot kerete – szövegre soha |
| ice (tech) | `#7CC4F5` | fókuszgyűrű, rácsháló, glow, eyebrow sötét alapon – kitöltésre soha |
| canvas / line | `#F5F7FA` / `#DCE4EC` | oldalháttér, vonalak |
| ink / ink-muted | `#14212F` / `#5B6B7C` | szöveg |
| ok / warn / err | `#1E9E6A` / `#D9781E` / `#D33A3A` | csak státusz |
| dark mode felület | `#0B1622` / `#122234` / line `#23384D` | navy-hangolt, nem fekete |

Fontok: **Poppins** 600/700 (címek) · **Inter** 400–600 (body, UI) · **JetBrains Mono** 500 (időpontok, árak, számok, `tabular-nums`).
Sugár: gomb 10 px · kártya 14 px · hero 20 px. Arány: 60 % világos / 30 % navy / 10 % sárga.
Tiltás: kék–lila gradiens, neon glow, üvegkártya, piros a márkában, emoji szekciójelölő, Poppins body-ra, Raleway, trópusi/alvó lajhár.

---

## 2. Oldalszerkezet — hol van kép, hol van animáció

Elv: **egy nagy „wow”-pillanat a heroban, utána csendes, funkcionális mozgás.** Minden szekció animációja `prefers-reduced-motion` esetén kikapcsol. Időzítés 150–250 ms mikro, 400–600 ms belépő; easing `cubic-bezier(.2,.8,.2,1)`.

| # | Szekció | Háttér | Kép / vizuál | Animáció | Asset |
|---|---|---|---|---|---|
| 0 | **Fejléc** (sticky) | átlátszó → scroll után `canvas` + alsó `line` | „4”-es ikon + `slot4u` wordmark balra; jobbra nav + sárga „Kipróbálom” gomb | scrollra 200 ms háttérváltás; gomb hover: −1 px emelés | `logo-icon.svg`, `wordmark.svg` |
| 1 | **Hero** | navy, 32 px rácsháló `ice` 10 %, jobb felső radial glow | **Bal:** eyebrow + H1 (egy szó sárgán) + lead + 2 gomb. **Jobb:** a *repülő hős-lajhár* (átlátszó PNG/SVG, ~420 px) **mögötte** az élő foglaló-widget (idősávok mono betűvel) | (a) betöltéskor: szöveg 3 lépésben fade-up (0/80/160 ms); (b) lajhár 600 ms alatt jobb-lentről berepül, majd **lassú lebegés** (±6 px, 4 s loop); (c) widget slotjai 40 ms-onként jelennek meg, a kiválasztott 11:15 kap egy sárga „pulse”-t 1×; (d) egérre a glow 2–3 px-t követi a kurzort (parallax, csak desktop) | `hero-sloth-flying.svg` (vektor!), `widget` = valódi komponens, nem kép |
| 2 | **Bizalmi sáv** | canvas | ⚠️ **Nem ügyféllogó** (l. a táblázat alatti dobozt): 4–5 ikon + rövid állítás, amelyek ügyfélszám nélkül is igazak — magyar fejlesztés · EU-s adattárolás (GDPR) · nincs havidíj, csak forgalom után · bankkártya nélkül kipróbálható | statikus rács, scroll-into-view fade-up 80 ms lépcsőben — **nincs marquee** | Lucide ikonok inline SVG, `ink-muted` |
| 3 | **Hogyan működik** (3 lépés) | canvas | 3 kártya, mindegyikben **egy-egy UI-részlet screenshot** (naptár nézet → ügyfél foglal → emlékeztető SMS/e-mail) 14 px sugárral, `line` kerettel | scroll-into-view: kártyák 80 ms lépcsőben fade-up; a képek belül **1 rövid, loopoló mikro-videó vagy Lottie** (pl. slot kiválasztás → pipa), max 3 s, hangtalan | 3× PNG @2x **vagy** 3× Lottie (`lottie-web` cdnjs) |
| 4 | **Funkciók** (6 blokk, 2×3 rács) | `brand-100` finom szekció-háttér | vonalas ikon (Lucide) `brand-100` dobozban, cím, 1 mondat | ikon-doboz hoverre `brand-200`-ra vált, ikon 1.05× – ennyi, semmi több | Lucide ikonok inline SVG |
| 5 | **Termék-bemutató** (nagy) | navy (2. sötét sáv – itt tér vissza a high-tech) | Egy **admin-dashboard mockup** böngésző-keretben, világos UI navy alapon, jobb oldalon a *laptopos lajhár* kis avatarként a sarokban | scrollra a mockup **kissé 3D-be dől** (rotateX 4° → 0°) és élesedik; a dashboard grafikonja **kirajzolódik** (SVG stroke-dashoffset, 900 ms) | `dashboard-mock.png @2x` (vagy élő komponens), `sloth-laptop.svg` |
| 6 | **Próbáld ki élőben** (demo tenantok) | `brand-100` szekció-háttér, a szekció felső élén 1 px `ice` vonal | Bal: 4 persona-kártya (Pszichológus · Szépségszalon · Fitnesz · Rendezvényház). Jobb: **élő, kattintható demo** böngésző-keretben (iframe a demo tenant publikus foglalóoldalára), felette „Ügyfélként foglalok / Adminként nézem” kapcsoló | kártyaváltás: keret tartalma 250 ms crossfade + 8 px csúszás, a kártya bal szélén sárga jelölő csúszik; „élő” zöld pulzáló pont a keret fejlécében; iframe csak `IntersectionObserver` után tölt | élő iframe (nem kép) + 4 tenant-logó SVG; mobilon iframe helyett screenshot + „Megnyitom” gomb — részletes spec: 2.1 |
| 7 | ~~**Vélemények**~~ | — | ⚠️ **KIMARAD** (l. a táblázat alatti dobozt): valós, engedélyezett ügyfél-idézet nélkül csak kitalált tartalom kerülhetne ide. Placeholderrel sem épül meg. Akkor tér vissza, ha vannak referenciák — akkor az eredeti terv áll vissza: 3 kártya, valódi fotó (természetes fény, nem stock), név, szakma, 1–2 mondat, `ok` színű „ellenőrzött” pipa, auto-forgás nélkül. | — | — |
| 8 | **Árazás** | canvas, kiemelt csomag navy kártya sárga „Népszerű” címkével | havi/éves kapcsoló; árak **JetBrains Mono**-ban | kapcsolóra az árak számláló-animációval váltanak (300 ms) | – |
| 9 | **GYIK** | canvas | accordion | nyitás 200 ms height + chevron 180° | – |
| 10 | **Záró CTA** | navy, rácsháló, glow | Bal: „Kezdd el ma, 5 perc.” + sárga gomb + „Nem kérünk bankkártyát” caption. Jobb: **integető / hüvelykujjas lajhár** (3. póz) | lajhár enyhe lebegés (ugyanaz a loop, mint a heroban) | `sloth-wave.svg` |
| 11 | **Footer** | navy, felül 1 px `ice` 20 % vonal | 4 oszlop, „4”-es ikon, jogi linkek | – | – |

> ### ⚠️ Két szekció, ami ügyfelet állítana — döntés (2026-09-06, Daniel)
>
> A terv eredetileg két helyen támaszkodott olyan társadalmi bizonyítékra, ami **ma nem létezik**:
> a **7. „Vélemények"** (3 valódi fotós ügyfél-idézet) és a **2. „Bizalmi sáv"** („Már X szolgáltató
> használja" + tenant-logók). Ügyfelek nélkül mindkettő csak kitalált tartalommal épülhetne meg — a
> bizalmi sáv esetében a doksi eredeti engedménye („a demo tenantok logói is jók") kifejezetten
> félrevezető lenne: a GlamZone és a Premium Fitness Studio **nem ügyfelek, hanem az általunk írt
> fixture-ök**, és aki felismeri őket a demóban, pont azt a bizalmat veszíti el, amiért a szekció készült.
>
> **Döntés:**
>
> * **A 7. szekció (Vélemények) kimarad** a főoldalról. Nem épül meg placeholderrel sem; akkor tér vissza,
>   ha van valós, hivatkozható és engedélyezett ügyfél-idézet. (SLO-205 scope-jából kivéve.)
> * **A 2. szekció megmarad, de ügyfél nélküli bizalmi jelzésre cserélve** — ugyanaz a vizuális hely és
>   funkció, csak olyan állításokkal, amik **ma is igazak**, és nem avulnak el. A logósáv bármikor a
>   helyükre léphet, ha lesznek referenciák. (SLO-204.)
>
> ⚠️ **SLA-számot (pl. „99,9% elérhetőség") NE írjunk ki**, amíg nincs mögötte vállalás — a `docs/17`
> monitoring, nem szerződéses ígéret. Az „adataid az EU-ban / GDPR" állítás mögött viszont ott a
> `docs/19`, a „nincs havidíj" mögött a `docs/10`, tehát ezek védhetők.

### 2.1 „Próbáld ki élőben” szekció — részletes spec (`docs/20`, publikus demo belépőpont → **SLO-192**)

**Cél:** a látogató regisztráció nélkül, **2 kattintásból** egy működő foglalófelületen vagy demo admin dashboardon áll. Ez a landing legerősebb konverziós eleme, mert a „lásd magad” erősebb bármely feature-listánál.

**Elrendezés (desktop, 1440):** 2 oszlop, 5/7 arány.

Bal oszlop — 4 persona-kártya, egymás alatt, 14 px sugár, `line` keret, a kiválasztott kártya `surface` háttér + bal szélén 3 px sárga jelölő:

| Kártya | Tenant | Méret-címke | 1 mondat | „Ezt próbáld ki” (a `docs/20` lefedettségi mátrixból) |
|---|---|---|---|---|
| Pszichológus, coach, terapeuta | `demo-pszichologus` | Egyszemélyes | Egyszemélyes praxis, diszkrét működés. | Első konzultáció foglalása → jóváhagyásra váró státusz; „Igazolás kérése” időpont nélkül |
| Szépségszalon, fodrász, kozmetika | `demo-szepsegszalon` | Több dolgozós | Több dolgozó, saját arculat. | Dolgozóválasztás vagy „bárki”; saját színpár/logó a foglalóoldalon |
| Fitnesz, jóga, edzőterem ⭐ | `demo-fitnesz` | Teljes | Csoportórák, várólista, online fizetés, két telephely. | Tele csoportórára várólista; szaunabérlés; sandbox-fizetés végigvitele |
| Rendezvényház, terembérlés | `demo-rendezvenyhaz` | Ajánlat-alapú | Ajánlatkérés, nem csak időpont. | Rendezvény-ajánlatkérés űrlap; tárgyalóbérlés jóváhagyással |

Minden kártyán két gomb: **„Foglalok ügyfélként”** (navy, elsődleges) → a tenant publikus foglalóoldala; **„Admin nézet”** (outline) → egykattintásos demo-login (aláírt, 15 perces token, `staff`/Manager szerep, `docs/20` §3.1 guardrailekkel). A sárga CTA ebben a szekcióban NEM jelenik meg — a hero és a záró CTA sárgája marad az egyetlen.

Jobb oszlop — **böngésző-keret** (navy fejléc, 3 pötty, a címsorban `demo-fitnesz.slot4u.hu` mono betűvel, jobbra zöld pulzáló „élő” pont). Benne `iframe` a kiválasztott tenant publikus foglalóoldalára, 16:10 arány, 14 px sugár, `float` árnyék (ez a szekció egyetlen lebegő eleme). A keret felett kapcsoló: **Ügyfél nézet / Admin nézet** — admin nézetben az iframe a demo-login tokennel a dashboardra tölt. A keret jobb alsó sarkában a *laptopos lajhár* kis avatar (48 px) tooltip-pel: „Minden éjjel visszaállítjuk — nyugodtan kattints bármit.”

Alatta egy sor caption (`ink-muted`, 13 px): „Fiktív adatok · nem küld e-mailt, SMS-t · fizetés sandbox · hajnali 3-kor visszaáll (`demo:reset` — SLO-191)”.

**Mobil (390):** kártyák vízszintes, görgethető chip-sor; iframe helyett a tenant foglalóoldalának screenshotja (`docs/design/foldal/06-demo-*.png`) és egy teljes szélességű „Megnyitom a demót” gomb, ami új lapon nyit. Admin nézet mobilon rejtve (a dashboard desktop-élmény).

**Animáció:** kártyaváltásra a keret tartalma 250 ms crossfade + 8 px csúszás felfelé, a címsor URL-je „gépelődik” (120 ms, mono); a sárga jelölő 200 ms alatt csúszik az új kártyára. Az iframe csak akkor tölt, ha a szekció a viewportba ér (LCP-védelem), addig a screenshot látszik placeholderként — így a crossfade sosem üres.

**Technikai feltételek (Claude Code-nak):**
- A demo tenantok válaszfejléce: `Content-Security-Policy: frame-ancestors 'self' https://slot4u.hu` (csak `is_demo = true` tenantnál; éles tenant sosem beágyazható).
- Demo-login: `GET /demo/login/{tenant}?t={signed}` → Laravel signed URL, 15 perc, csak `is_demo` tenantra, minden hívás `demo_logins` logba; rate limit 10/perc/IP.
- Az iframe `sandbox="allow-same-origin allow-scripts allow-forms allow-popups"`, `loading="lazy"`, `title` kitöltve.
- A demo foglalóoldalon egy vékony felső sáv: „DEMO · fiktív adatok” (`warn` háttér, `navy` szöveg) — a látogató sose higgye valósnak.
- Sikeres demo-foglalás után a visszaigazoló oldalon egy kártya: „Tetszett? Ilyet kapsz te is 5 perc alatt →” (navy gomb a regisztrációra) — ez a szekció valódi konverziós pontja.
- Esemény-mérés (GA4/Clarity): `demo_select_persona`, `demo_open_public`, `demo_open_admin`, `demo_booking_completed`, `demo_cta_register`.

> ### ⚠️ Megvalósítási megjegyzés (SLO-192, 2026-09-06) — mi épült meg és mi tér el
>
> **Az útvonal alakja más, mint a fenti spec.** Nem `GET /demo/login/{tenant}?t={signed}` a központi
> domainen, hanem **`GET /demo/login` a tenant saját aldoménjén**, Laravel-natív aláírással
> (`URL::temporarySignedRoute`). Így a tenantot a meglévő `identify.tenant` middleware oldja fel —
> nem kell egy második, kézzel írt tenant-feloldás arra az egyetlen útvonalra, ami munkamenetet ad
> névtelen látogatónak. Az aláírás a teljes URL-t fedi, a lejárat 15 perc, a rate limit 10/perc/IP.
>
> **Nincs külön `demo_logins` tábla.** Minden belépés az alkalmazásnaplóba megy (`Log::info`,
> tenant + user_id + IP). Egy tábla itt olyan adatot tartana el, amit senki nem kérdez le, viszont
> naponta 03:00-kor a `demo:reset` mellé kellene takarítani is.
>
> **Amiért a látogató landol: Manager, nem tulajdonos.** Amit a Manager *nem* ér el, az fele annak,
> amit a jogosultsági mátrix megmutat (`docs/03`); tulajdonosként belépve a demo egy olyan
> jogosultsági modellt mutatna, aminek nincs mit mutatnia. Ahol nincs Manager (egyszemélyes praxis),
> a tenant-admin a tartalék.
>
> **A persona-lista az `is_demo` jelzőből jön**, nem a landing forrásából. Egy oldal, ami négy
> personát a saját kódjában sorol fel, az az oldal, ami az ötödikre nem linkel és a törölt negyediket
> tovább hirdeti. A `demo-smoke` és a nem aktív tenantok kimaradnak.
>
> **Ami elmaradt, és miért:** a keret sarkában a *laptopos lajhár* avatar és a placeholder-screenshotok
> (`docs/design/foldal/06-demo-*.png`) — mindkettő **SLO-202** asset, ami még nem létezik. A keret
> helyükön skeletont mutat, mobilon pedig a screenshot helyett a chip-sor + „Megnyitom a demót" gomb
> áll. Ha az assetek megjönnek, csak a placeholder cserélődik.
>
> **Ami többet kapott a specnél:** a „DEMO · fiktív adatok" sáv nem csak a publikus foglalóoldalon
> van, hanem a demo tenant **admin felületén is** — a látogató az iframe-ből bárhová kattinthat, és
> egy sáv, amire csak egy controller emlékszik, a második oldalon eltűnik. Ezért a `tenant.is_demo`
> megosztott Inertia propból jön.

**Miért ez, és nem screenshot-galéria:** a célcsoportod (pszichológus, szalon, edző) nem feature-listát vesz, hanem azt, hogy *az ő ügyfele* mit fog látni. Az élő iframe ezt a kérdést 10 másodperc alatt megválaszolja, és egyben a „high-tech” ígéretet is bizonyítja — a rendszer nem kép, hanem fut.

**Üres állapotok és 404 az appban:** a lajhár *ül a laptopnál* póz + 1 mondat. Ez a kabala 4. pózának helye, a landing oldalon nem kell.

**Kabala-pózok összesen (vektorban kell):** 1) repülő hős (hero) · 2) laptopos (dashboard sarok, üres állapotok) · 3) integető/hüvelykujj (záró CTA) · 4) lapos 1-színű fej-ikon (favicon, app-ikon, fejléc). Mind nyitott szemmel.

**Fotó-irány:** valódi szolgáltatók munka közben, természetes fény, semleges háttér; enyhe navy színkorrekció, hogy a paletta alá simuljon. Stock-mosoly és kék-fehér irodai fotó tilos.

**Teljesítmény-korlát (Inertia SSR):** hero LCP-elem a H1 legyen, nem a lajhár; lajhár SVG inline vagy `<img fetchpriority="high">`; minden más kép `loading="lazy"`; Lottie/marquee csak `IntersectionObserver` után indul. Cél: LCP < 2,0 s mobilon.

---

## 3. Így add át Claude Design-nak

A Claude Design akkor tartja a palettát, ha **tokenként** kapja, nem leírásként. Két lépés:

**3.1 Design System létrehozása.** Új Claude Design-munkamenetben először egy *Design System* artifactot kérj ezzel a szöveggel (szó szerint bemásolható). A KONTEXTUS-blokk kötelező: enélkül a modell fogyasztói foglaló-piacteret tervez (ügyfél-oldali „Időpontjaim" nézetet), nem a szolgáltatóknak szóló SaaS-főoldalt.

```
KONTEXTUS (ezt minden képernyőnél vedd figyelembe):
A slot4u egy magyar, multi-tenant online időpontfoglaló SaaS. Vevőink SZOLGÁLTATÓK:
pszichológus, coach, masszőr, gyógytornász, dietetikus, személyi edző, jógastúdió,
szépségszalon, rendezvényhelyszín. Ők kapnak saját foglalóoldalt (sajat-nev.slot4u.hu),
naptárt, emlékeztetőket, online fizetést, admin dashboardot.
A most tervezendő oldal a slot4u.hu FŐOLDAL: B2B marketing landing, amely a
SZOLGÁLTATÓNAK adja el a rendszert. Nem piactér, nem az ügyfél foglal itt, nincs
bejelentkezett végfelhasználó, nincs "Időpontjaim" menü. Fejléc-menü: Funkciók ·
Kinek ajánljuk · Árak · Demo · Belépés (outline) · Kipróbálom (sárga).
Fő üzenet: "Te pihensz, a foglalást mi intézzük." Hang: nyugodt, profi, barátságos.
Kabala: szuperhős-lajhár (nyitott szem, navy ruha, sárga 4-es) – a hero jobb oldalán
és a záró CTA-nál jelenik meg; a mellékelt PNG a helyét jelöli.
Referencia a szerkezethez: 11 szekció (fejléc, hero, bizalmi sáv, hogyan működik,
funkciók, termékbemutató, próbáld ki élőben, vélemények, árazás, GYIK, záró CTA, footer)
– ezeket külön kérem, most csak a design system kell.

Hozz létre egy design systemet "slot4u" néven az alábbi tokenekkel, és minden későbbi
képernyőt ebből építs:

Colors: navy #0D1B2A (alap, hero/footer háttér, elsődleges gomb világos felületen);
brand #1B4F72 (linkek, ikonok, aktív nav); brand-100 #E6F0F8 és brand-200 #C9DCEC
(ikon-háttér, badge, hover); accent #F4B942 (KIZÁRÓLAG egy fő CTA képernyőnként és
egy kiemelt szó a címben – szövegszínnek soha); ice #7CC4F5 (csak fókuszgyűrű, 1px
rácsháló, glow – kitöltésre soha); canvas #F5F7FA; line #DCE4EC; ink #14212F;
ink-muted #5B6B7C; ok #1E9E6A; warn #D9781E; err #D33A3A (csak státusz).
Dark mode: surface #0B1622 / #122234, line #23384D, a többi változatlan.

Typography: Poppins 700/600 címekre (40/28/20 px, -0.02em); Inter 400–600 body és UI
(17/15/13 px); JetBrains Mono 500 minden időpontra, árra, számra (tabular-nums);
label 11 px Inter 600, +0.12em, nagybetűs.

Shape: border-radius gomb 10px, kártya 14px, hero 20px. Árnyék csak lebegő elemen:
0 1px 2px rgba(13,27,42,.06), 0 8px 24px rgba(13,27,42,.08). Kártyák alapból csak
1px line kerettel, árnyék nélkül.

Stílus: letisztult, modern, barátságos, high-tech. Világos oldaltest, navy hero és
footer. Arány 60% világos / 30% navy / 10% sárga. Ikonok: Lucide outline 1.75px.
TILOS: kék-lila gradiens, neon, üvegkártya, piros díszítésként, emoji, Raleway.
```

**3.2 A képernyők kérése.** Utána a landing oldalt szekciónként kérd, a 2. pont táblázatának megfelelő sorát bemásolva. Csatold a lajhár PNG-t. Egy artboard = egy szekció desktop (1440) + egy mobil (390) — így Claude Code később szekciónként tudja kódolni. Minta a hero-ra:

```
Tervezd meg a slot4u.hu főoldal HERO szekcióját a "slot4u" design systemből,
1440 px desktop és 390 px mobil artboardon. Célközönség: szolgáltatók (pszichológus,
szalon, edző), akik saját online foglalórendszert keresnek – NEM az ő ügyfeleik.

Fejléc felette: slot4u wordmark balra (a 4 sárga), menü: Funkciók · Kinek ajánljuk ·
Árak · Demo; jobbra "Belépés" outline gomb és "Kipróbálom" sárga gomb.

Hero: navy háttér 32 px rácshálóval (ice 10%), jobb felső halvány radial glow.
Bal oszlop: eyebrow "ONLINE IDŐPONTFOGLALÁS SZOLGÁLTATÓKNAK"; H1 Poppins 700:
"Te pihensz, a foglalást mi intézzük." – az "a foglalást mi intézzük" rész sárga;
lead: "Saját foglalóoldal, naptár, emlékeztetők és online fizetés – öt perc alatt
beállítva, papírnaptár nélkül."; gombok: "Ingyenes kipróbálás" (sárga) + "Nézd meg
a demót" (ghost). Caption alatta: "Nem kérünk bankkártyát".
Jobb oszlop: a csatolt repülő lajhár (~420 px), mögötte/alatta egy világos
foglaló-widget kártya "Szabad időpontok · csütörtök" címmel, 3×3 időpont-ráccsal
JetBrains Mono-ban (09:00, 09:45 áthúzva, 10:30, 11:15 kiválasztva navy+sárga
kerettel, …), alul navy gomb "Foglalás 11:15-re".
Ne rajzolj bejelentkezett felhasználót, "Időpontjaim" menüt vagy szolgáltatás-
árlistát – ez nem az ügyfél nézete.
```

---

## 4. Így kapja meg Claude Code a kész tervet

Claude Code nem „látja” a Claude Design-vásznat, fájlokat lát. Ezért az átadás mindig a repón keresztül megy:

1. **Export.** Claude Design-ból szekciónként exportáld PNG-be (desktop + mobil), és ha elérhető, a design HTML/„code” exportját is. Tedd ide: `docs/design/foldal/01-hero-desktop.png`, `01-hero-mobile.png`, … (a 2. pont sorszámai szerint).
> ### ⚠️ Megvalósítási megjegyzés (SLO-201, 2026-09-06) — hol laknak valójában a tokenek
>
> A `@theme inline` **nem írja ki** a `--color-*` neveket a `:root`-ba: minden értéket közvetlenül
> a generált utility-be fejt ki. Ezért a paletta **hex értékei a `:root`-ban laknak** saját néven
> (`--navy`, `--brand`, `--ice`…), és az `@theme inline` ezekre hivatkozik
> (`--color-navy: var(--navy)`). Ugyanaz az alak, amit a shadcn szerepek már használnak.
>
> **Miért nem mindegy:** ha egy szerep `var(--color-canvas)`-t írna, az futásidőben **nem létező**
> névre mutatna, és **csendben semmivé oldódna** — stílustalan oldal, nem fordítási hiba. Ez a
> `BrandColorTest` óta (SLO-170) őrzött csapda, és pontosan ezt a változtatást is elkapta.
>
> **Két eltérés a §1 nevezéktanától, mindkettő kényszer:**
> * A sárga a kódban **`highlight`**, nem `accent` — a shadcn már birtokolja az `--accent`-et egy
>   másik szerepre (a menüpont halvány hover-háttere), és minden komponense azt olvassa. Ha a
>   sárgát tennénk oda, az admin fele sárga lenne — épp az, amit a „1 CTA / képernyő" szabály tilt.
> * A **`--primary` navy**, nem sárga: az minden elsődleges gomb és aktív nav színe. A sárga a
>   `--color-highlight`, amit szekciónként egyszer szabad elővenni.
>
> **A platform-accent megszűnt.** A `MarketingLayout` és az `AppLayout` korábban a slot4u tealjére
> festette a `--primary`-t (SLO-170); erre nincs többé szükség, mert **az arculat maga az
> alapértelmezés**. Így az app **egyetlen** futásidejű `--primary`-felülírása maradt: a `PublicLayout`,
> ami a tenant saját színét teszi rá. Erre teszt van (`IdentityTokensTest`).
>
> ⚠️ **Amit ez érint:** a tenant admin és az auth képernyők eddig a semleges violetet kapták, most
> navy-t. A **tenant publikus foglalóoldala változatlan** — azt a `TenantBranding` mindig felülírja
> (alapból indigo), tehát a bolt kirakata az övék marad (`docs/19` §2).

2. **Tokenek kódban.** ⚠️ **Nincs `tailwind.config`** — a projekt **Tailwind CSS 4**-et használ, ahol a tokenek a `resources/css/app.css` **`@theme inline`** blokkjában élnek, a meglévő shadcn változók (`--primary`, `--border`, …) mellett. Ez a *single source of truth*; a design-ban látott hex sosem kerül közvetlenül a komponensbe. ⚠️ A token-csere a **meglévő admin felületre is hat**, mert a shadcn komponensek ugyanezekből a változókból épülnek — a hatókört külön issue kezeli.
3. **Assetek.** `public/brand/`: `logo-icon.svg`, `wordmark.svg`, `sloth-flying.svg`, `sloth-laptop.svg`, `sloth-wave.svg`, `favicon.svg`, `app-icon-512.png`. Vektor a kabalából kötelező (Illustrator Image Trace / Recraft / Vectorizer.ai).
4. **CLAUDE.md sor.** `Arculat és főoldal-terv: docs/21-foldal-design-terv.md (kötelező), design exportok: docs/design/foldal/`.
5. **A prompt Claude Code-nak** (szekciónként, ne az egész oldalt egyben):
   ```
   Olvasd el docs/21-foldal-design-terv.md 1–2. pontját és nézd meg
   docs/design/foldal/01-hero-desktop.png + 01-hero-mobile.png.
   Implementáld a hero szekciót Inertia + React (TSX) komponensként a Tailwind 4
   tokenekből (resources/css/app.css @theme inline), pixelközeli hűséggel,
   az animációkat a 2. pont táblázata
   szerint (prefers-reduced-motion tisztelettel). Assetek: public/brand/.
   Ne találj ki új színt vagy fontot.
   ```
6. **Ellenőrzés.** Claude Code Playwright-screenshotot készít 1440 és 390 px-en, egymás mellé teszi az exporttal; eltérésnél a tervet követi, nem a saját ízlését.

Ha Claude Design nem ad HTML-exportot, a PNG + ez a doksi + a tokenek együtt bőven elég: a táblázat mondja meg a szerkezetet, a kép a vizuált, a config a színt.
