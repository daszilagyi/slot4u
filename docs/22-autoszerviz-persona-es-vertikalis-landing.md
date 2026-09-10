# 22 — Autószerviz persona és vertikális landing (specifikáció)

**Státusz:** jóváhagyott spec — Linearba felvíve 2026-09-06: SLO-197 (persona), SLO-198 (landing), SLO-199 (custom fields szelet, P2), **SLO-200 (erőforrás-kiosztás — az SLO-197 blokkolója)**. Lásd 6. fejezet.
**Dátum / döntéshozó:** 2026-09-06, Daniel.
**Kontextus:** az M9 demo-keretrendszer (`docs/20`) és a 4 persona kész. Ez a doksi egy ÖTÖDIK, nem-wellness vertikálist (kis autószerviz) ad a demóhoz, és egy hozzá tartozó vertikális landing oldalt (`slot4u.hu/autoszerviz`). Célja kettős: (1) bizonyítani, hogy a foglalási motor iparág-független, (2) egy külön hirdethető, mérhető sales-belépőpontot adni egy új célközönségnek.
**Kapcsolódó docs:** 02 (adatmodell), 04 (foglalási módok), 07 §3 (custom fields — P2), **20** (demo seed), **21** (arculat + landing terv, különösen §2.1).

> **Repo-szinkron (2026-09-06 — kész):** a fájl bekerült a repóba `docs/22` néven (a 16-os
> számot a deploy pipeline, a 18-ast a backup doksi viszi). A `docs/20` lefedettségi mátrixának
> kiegészítése (5. fejezet) és a CLAUDE.md doksi-táblájának frissítése az SLO-197 feladata.
>
> ⚠️ **Alkalmassági korrekció (2026-09-06):** az 1. fejezet táblázata az „emelő/állás mint fő erőforrás"
> sort **tévesen jelölte „MVP kész"-nek**. A motor a helyiséget csak akkor veszi figyelembe, ha a
> látogató **kiválasztotta** — nincs automatikus állás-kiosztás. Részletek és a következmény: 1. fejezet
> alatti dobozban. Ezt az **SLO-200** építi meg, és az blokkolja az SLO-197-et.

---

## 1. Alkalmassági elemzés — mit kér egy kis szerviz, és mire képezhető le

Célszegmens: **1–3 szerelős, 2–4 állásos független autószerviz / gumis műhely**, ahol ma telefonon foglalnak és füzetbe írnak. NEM célszegmens: márkaszerviz, DMS-t (munkalap, alkatrészkészlet, tételes számlázás) használó nagy műhely.

| Szerviz-igény | slot4u leképezés | Állapot |
|---|---|---|
| Fix hosszú munkák (olajcsere, gumicsere, fék, klíma, diagnosztika) | `duration_based`, szolgáltatásonként eltérő `duration` + `buffer` | MVP kész |
| A fő erőforrás az **emelő/állás**, nem a szerelő | `rooms` = állások; `requires_room = true`, `requires_staff = true` (vagy „bárki") — a két elérhetőség metszete | ✅ **KÉSZ (SLO-200)** — a rács a metszeten áll, a foglalás kioszt egy szabad állást |
| Egész napos leadás (műszaki vizsga, vezérműszíj) | `duration_based` 240–480 perc, egy állást egész napra lefoglal | MVP kész |
| „Nézze meg a szerelő, aztán mondjon időpontot" | `requires_approval = true` → `requested` → jóváhagy / elutasít / más időpontot ajánl | MVP kész |
| Árajánlat nagyobb javításra hibaleírás alapján | `quote_request`, `parameters` json (rendszám, típus, évjárat, km, hibaleírás) | MVP kész |
| Gumiszezon-csúcs, párhuzamos állások | több room + sávzár ütközésvédelem; naptár helyiség/dolgozó szűrés | MVP kész |
| Szabadság, ünnep, fél napos szombat | `schedules` + `schedule_exceptions` | MVP kész |
| Emlékeztető SMS/e-mail | M5 értesítések | kész |
| **Rendszám / jármű strukturáltan** | custom fields (docs/07 §3, SLO-60) | **P2 — nincs kész**, áthidalás: 3. fejezet |
| Több autó egy ügyfélnél, jármű-előzmény, munkalap, alkatrész | szerviz-menedzsment (DMS) | **scope-on kívül, szándékosan** |

> ### ⚠️ A metszet valósága — amit ellenőriztem a kódban (2026-09-06)
>
> Az `AvailabilityService::primaryResources()` `duration_based` módban **mindig a STAFF munkarendjéből**
> építi a rácsot. A helyiség csak akkor szűkít, ha a hívó **explicit átadott egy `room_id`-t**
> (`if ($type === 'staff' && $roomId !== null)`). Ha a látogató nem választ állást:
>
> * a helyiség munkarendje **nem korlátozza** a felajánlott sávokat, és
> * a foglalás `room_id = null`-lal jön létre, tehát a `CreateBooking::hasResourceConflict()`
>   **nem védi az állást** — két szerelő két foglalása ütközhet ugyanabban az emelőben, észrevétlenül.
>
> **Következmény erre a personára:** a *szombati gumis demó* működik ma (a Kerékcsere csak Baloghhoz
> van rendelve, tehát az ő munkarendje egyedül elvégzi a szűrést), de a „3 állás, bárki szerelő"
> alaphelyzet — a persona fő értékajánlata — **nem**. Ez nem demó-probléma, hanem termékhiány: bárkit
> érint, akinek több azonos erőforrása van (2 kezelőágy, 3 emelő, 4 szolárium).
>
> **Megoldva: SLO-200** (automatikus erőforrás-kiosztás) — a rács a staff- és helyiség-munkarend metszetén
> áll, a `CreateBooking` pedig a lock alatt kioszt egy szabad állást. Az SLO-197 innentől nem blokkolt.
> A részletek a `docs/04` 2. módjánál.

**Pozicionálás (a landing és a demo hangneme ebből következik):** a slot4u a szerviznek *online időpontfoglaló + emlékeztető*, nem műhelyszoftver. Az üzenet: „a szerelő ne a telefont vegye fel szerelés közben; a vendég éjjel is foglaljon; senki ne felejtse el az időpontot". A DMS-funkciók hiányát nem szégyelljük, kimondjuk: „nem cseréljük le a munkalapot — a naptáradat cseréljük le".

---

## 2. Persona 2.5 — „Csavarkulcs Autószerviz" · **több dolgozós méret**

> ⚠️ **Csomag-korrekció (2026-09-06):** a lépcsős Alap/Közepes/Max csomagmodell **megszűnt** (CLAUDE.md,
> `docs/10`) — a monetizáció forgalom-alapú jutalék. A „Közepes csomag" címke innentől csak **méretet**
> jelent, nem funkció-bundle-t. Az egyetlen ingyenes `base` plan limitjei **8 dolgozó / 3 helyszín /
> 8 helyiség** (SLO-195), amibe ez a persona (3 staff / 1 helyszín / 3 helyiség) bőven belefér.

Fiktív név (nem létező márka — ellenőrizd seed előtt, hogy nincs ilyen nevű valós vállalkozás a subdomainhez tartozó szövegekben; ha van, cseréld `Garázs 42 Autószerviz`-re). A kis független szerviz demója: 3 állás, 3 szerelő, gumiszezon, egész napos leadás, jóváhagyás és ajánlatkérés egy tenanton belül.

- **Subdomain:** `demo-autoszerviz` · timezone Europe/Budapest · locale hu · `is_demo = true`
- **Seeder osztály:** `database/seeders/Demo/AutoServiceDemoPersona.php` (a konvenció `…DemoPersona`, nem `…DemoSeeder`), a `DemoSeeder::PERSONAS` listába regisztrálva; fix faker seed; MINDEN dátum `Carbon::today()`-hoz relatív (`docs/20` §3.2 szerint). ⚠️ **Minden seedelt időpont 03:00-kor is legyen a múltban** — a `demo:reset` ekkor fut (SLO-191), és ez mind a négy meglévő persona kőbe vésett határfeltétele.
- **Branding:** `branding` json kitöltve — saját színpár (sötét antracit + narancs jelzőszín; NEM a slot4u navy/sárga) + egyszerű csavarkulcs-ikonos SVG logó. Ez a testreszabás-demó egy „nem szépségipari" arculattal. ⚠️ A `feature_branding` **alapból ki van kapcsolva** a base planen — a seedernek `TenantFeature`-rel be kell kapcsolnia (l. `FitnessDemoPersona::enableFeatures()`).
- **Struktúra:**
  - 1 helyszín: „Csavarkulcs Autószerviz — Budapest, ipari park" (fiktív cím, XVI. kerület jellegű ipari terület, valós utcanév NÉLKÜL)
  - 3 helyiség = **állás** (`rooms.type` mezőben vagy leírásban jelölve: `bay`): *1-es állás (emelő)*, *2-es állás (emelő)*, *Gumis állás*
  - 3 staff: *Kovács Gábor* (szerelő, tulajdonos — ő a tenant-admin user is, `staff.user_id` kitöltve), *Németh Attila* (szerelő), *Balogh Zsolt* (gumis / gyorsszerviz)
  - Admin userek: tulajdonos (Admin) + *Szabó Kinga* szervizfogadó (**Manager** role — időpontot kezel, jóváhagy, ajánlatot ad, de előfizetéshez/beállításhoz nem fér hozzá)
- **Szolgáltatások** (4 kategória): árak = munkadíj, alkatrész nélkül (ezt a szolgáltatás `description` mezőjében ki kell írni — reális elvárás-kezelés)

| Kategória | Szolgáltatás | Mód | Időtartam / buffer | Ár (HUF) | Staff | Room | Extra |
|---|---|---|---|---|---|---|---|
| Gyorsszerviz | Olajcsere szűrővel | `duration_based` | 60 p / 15 p | 9 900 | bárki (Kovács, Németh) | 1-es, 2-es | — |
| Gyorsszerviz | Hibakód-olvasás, diagnosztika | `duration_based` | 30 p / 10 p | 6 000 | Kovács, Németh | 1-es, 2-es | — |
| Gyorsszerviz | Klímatöltés + fertőtlenítés | `duration_based` | 45 p / 15 p | 15 000 | Németh | 2-es | — |
| Gumi | Kerékcsere (szezonális, 4 kerék) | `duration_based` | 45 p / 15 p | 8 500 | bárki (Balogh, Németh) | Gumis állás | a szezon-csúcs szolgáltatása |
| Gumi | Gumiszerelés felniről + centrírozás | `duration_based` | 60 p / 15 p | 12 000 | Balogh | Gumis állás | — |
| Fék & futómű | Fékbetét csere (első tengely) | `duration_based` | 90 p / 15 p | 14 000 | Kovács, Németh | 1-es, 2-es | — |
| Nagyobb munkák | Műszaki vizsga felkészítés + vizsgáztatás | `duration_based` | 480 p (egész napos leadás) / 0 | 25 000 | Kovács | 1-es | `requires_approval = true` |
| Nagyobb munkák | Vezérműszíj csere | `duration_based` | 240 p / 30 p | 45 000 | Kovács | 1-es | `requires_approval = true` |
| Nagyobb munkák | Ajánlatkérés nagyobb javításra | `quote_request` | — | egyedi | — | — | `parameters`: rendszám, márka/típus, évjárat, km-óra, hibaleírás, kívánt időszak |

- **Rendszám / járműadat (MVP áthidalás — 3. fejezet):** minden seedelt `bookings.notes` értéke egységes mintát követ: `Rendszám: ABC-123 · Skoda Octavia (2016) · 148 000 km` (fiktív, de formailag valós magyar rendszám-minta: 3 betű-3 szám és az újabb 4 betű-3 szám formátum vegyesen). A publikus foglalóoldalon a megjegyzés mező **szolgáltatás-szintű súgószövege** (`services.settings.notes_hint`, ha a mező már létezik a settings json-ban; ha nem, a tenant-szintű lang override) legyen: „Kérjük, add meg a rendszámot és az autó típusát."
- **Munkarend:** H–P 8:00–17:00 mindhárom szerelőnél és mindhárom álláson; **szombat 8:00–12:00 CSAK a Gumis állás + Balogh** (a room- és staff-munkarend metszete így látványosan eltér). 1 jövőbeli `schedule_exceptions` (Németh szabadság, 2 nap) + 1 múltbeli egész napos zárva (ünnep).
- **Ügyfelek:** 40 · **Előzmény:** ~180 nap, napi 5–8 foglalás; **gumiszezon-csúcs**: a seed napjához képest a −150…−120 napos ablakban a Kerékcsere aránya 3×-os (a szezonalitás a statisztikában mindig látszik, naptári hónaptól függetlenül — determinisztikus). Visszatérő ügyfelek: top 5 ügyfélnek 6+ foglalása (olajcsere + kerékcsere ismétlődő mintázat).
- **Jövő:** +14 nap részben foglalt naptár mindhárom álláson; legalább **1 `requested`** (műszaki vizsga jóváhagyásra vár), **1 `rejected`** indoklással („Erre a napra nincs vizsgaidőpont — ajánlott: +2 nap"), 1 `no_show`, 2 `canceled`
- **Ajánlatkérések:** minden státuszban legalább 1 (`new`, `in_progress`, `quoted` árral + érvényességgel, `accepted` generált bookinggal az 1-es állásra, `rejected`); legalább 1 kérelmen belül üzenetváltás („Tud fotót küldeni a hibáról?" — „Csatolom holnap" — szöveges, fájl nélkül)
- **Üzenetsablon:** 1 testreszabott visszaigazoló `message_template` szerviz-hangnemben: „Az autót a foglalt időpontra hozd be, a kulcsot a pultnál add le. Munkadíj alkatrész nélkül értendő."
- **Tartalmi szabály:** semmi valós márkanév a szerviz nevében; autómárkák (Skoda, Toyota, Ford, Opel, Suzuki, VW) a jegyzetekben rendben (termékkategória, nem partner-állítás). Nincs valós telefonszám/cím.
- **Plan-limitek:** a `base` plan **8 dolgozó / 3 helyszín / 8 helyiség** (SLO-195) — a 3/1/3 bőven belül.

### Miért érdemes: mit demóz ez, amit a másik négy nem

1. `duration_based` + `requires_room` + `requires_staff` **egyszerre** — a két munkarend metszete (szombati gumis nyitás) a naptáron látványos.
2. Egész napos (480 perces) szolgáltatás egy álláson — „leadás" típusú foglalás.
3. Approval + quote + sima foglalás **egy tenanton belül** (a rendezvényház quote-fókuszú, a pszichológus approval-fókuszú).
4. Nem-wellness arculat a branding-demóban.
5. Szezonalitás a statisztikában.

---

## 3. Járműadat-kezelés — döntés

**MVP/demo:** a rendszám és a jármű a `bookings.notes` mezőben, szolgáltatás-szintű súgószöveggel. Ez elég a demóhoz és az első szerviz-ügyfeleknek.

**Következő lépés (P2, külön issue — 6. fejezet C):** az SLO-60 custom fields egy **minimál szelete**: `entity_type = booking`, típusok `text | number | select`, szolgáltatáshoz kötve (csak az adott szolgáltatás foglalásánál kérdezi), `required` támogatással, megjelenik a foglalás-részletekben, admin naptár tooltipben és a visszaigazoló e-mailben. NEM része: `sensitive` titkosítás, file mező, ügyfél-szintű mezők, form builder. Ha ez elkészül, a demo seed frissül: a rendszám külön mezőbe kerül, és a szépségszalon persona is kap 1 mezőt (pl. „Hajhossz").

Indok: sales-beszélgetésben a szerviz első kérdése a rendszám lesz; a notes-megoldás demónak jó, de a második szerviz-ügyfélnél már fájni fog. Ugyanez a szelet a szalonnak (allergia — de az sensitive, tehát ott csak a nem-érzékeny mezők) és a fitnesznek is használható.

---

## 4. Vertikális landing — `slot4u.hu/autoszerviz`

**Miért külön oldal, nem 5. kártya a főoldalon:** a főoldal demo-szekciója 4 kártyára van tervezve (`docs/21` §2.1), a főoldal hangneme wellness/szolgáltató; a szerviz ott kilóg. Külön oldal viszont: saját Meta Ads hirdetéscsoport és landing → tiszta konverziómérés vertikálisonként; SEO-ban az „autószerviz időpontfoglaló / online időpontfoglalás autószerviz" kifejezésre csak dedikált oldal rangsorolhat; és **ugyanez a sablon** adja később a `/pszichologus`, `/szepsegszalon`, `/fitnesz`, `/rendezvenyhaz` oldalakat.

**Technika:** a slot4u.hu landing stackjében (`docs/21` §4 — **Inertia v3 + React 19 SSR**, Tailwind 4 `@theme inline` tokenek), a főoldal komponenseinek újrahasznosításával. **Új komponens nem készül**, ha a főoldalé paraméterezhető: a „Próbáld ki élőben" böngésző-keret komponenst (SLO-192) `persona` propval egy tenantra szűkítve használjuk, kártyalista nélkül. Ha az SLO-192 komponense még nem paraméterezhető, az SLO-192 PR-jében kell azzá tenni (ne itt forkoljuk).

**Arculat:** `docs/21` §1 tokenek kötelezőek (navy/brand/accent/ice; Poppins/Inter/JetBrains Mono; 1 sárga CTA / képernyő; tiltólista érvényes). A szerviz-tenant saját narancs arculata CSAK az iframe-ben látszik — a landing maga slot4u-arculatú.

> ### ⚠️ Megvalósítási korrekciók (SLO-198, 2026-09-09) — a lenti tábla négy pontján
>
> 1. **7. sor, árazás — „Közepes csomag kiemelve" NEM épült meg, mert nincs mit kiemelni.**
>    A háromlépcsős csomagmodell megszűnt (CLAUDE.md, `docs/10`); az oldal a **forgalom-alapú
>    jutalékot** hozza, ugyanabból a `BuildPublicCommissionTerms` szolgáltatásból, mint a főoldal.
>    Az indoklás a §7.4 szándéka szerint a szerviz konkrét igényére épül („3 állás + szombati gumis
>    munkarend + statisztika"), nem feature-listára. A tábla 7. sora ezen a ponton elavult.
> 2. **3. és 5. sor, screenshotok — komponensek, nem képek.** Nincs böngésző a projektben, amivel
>    demo-screenshot készülne, és ugyanez a döntés született az SLO-203/204-ben is (`HowItWorks`,
>    `ProductShowcase`): egy admin-képernyőről készült kép csendben elavul, mert semmi nem törik el
>    tőle, és aki legközelebb hozzányúl a képernyőhöz, nem tudja újra elkészíteni. Az OG-kép a
>    meglévő platform-kártya. Az 5. sor mobil viselkedése ettől nem sérül: a `TryItLive` mobilon
>    amúgy is kilinkel az iframe helyett.
> 3. **Tartalom-fájl: `lang/hu/app.php` → `verticals.{slug}`, nem `content/verticals/*.json`.**
>    A repó egyetlen szövegtárolója a lang fájl (Inertia shared prop → `t()`), és egy második,
>    párhuzamos tároló bevezetése többe került volna, mint amennyit ér. A sablon-elv ugyanaz marad,
>    és teszt bizonyítja: egy új `verticals.*` blokk + egy sor `config/verticals.php` elég, komponenst
>    nem kell hozzányúlni.
> 4. **UTM-attribúció: azóta megépült (SLO-210).** Az SLO-198 idején még nem volt mihez kötni; ma van:
>    `tenants.signup_utm_source/_medium/_campaign/_landing_path/_landed_at` (`docs/02`), a rögzítés a
>    **session**ben utazik a központi marketingfelületről (`CampaignAttribution`), és a superadmin
>    tenant-listán kampányonkénti bontás látszik.
>    ⚠️ **Nem süti, és ez tudatos:** egy attribúciós sütihez hozzájárulás kellene, a hozzájárulási
>    arány pedig közönségenként eltér — vagyis épp abban a dimenzióban torzítana, amit ez az egész
>    össze akar hasonlítani. Az ára: az attribúció a látogatás idejéig él, tehát a késleltetett
>    konverziót alulmérjük — egyenletesen, ezért a **vertikálisok összehasonlítása** túléli.
>    A teljes indoklás: `docs/19` §12. A GA4/Meta oldali `vertical` paraméter és a `Lead` esemény
>    változatlanul megvan.
>
> Ami a leírásból tárgytalan lett: a „ha a `TryItLive` nem paraméterezhető, tedd azzá" pont — az
> `only` prop az SLO-192-ben megépült, itt csak használjuk.

**Szekciók (desktop 1440 / mobil 390, a `docs/21` §2 animációs elvei szerint, `prefers-reduced-motion` tisztelve):**

| # | Szekció | Tartalom | Megjegyzés |
|---|---|---|---|
| 0 | Fejléc | főoldali fejléc, nav-ban „Vissza a főoldalra" | sticky, mint a főoldalon |
| 1 | Hero | Eyebrow: „Autószervizeknek". H1: „A vendéged éjfélkor is tud **időpontot** foglalni. Te közben szerelsz." (sárga szó: időpontot). Lead: „Online időpontfoglalás és automatikus emlékeztető kis szervizeknek, gumisoknak. Nem műhelyszoftver — a füzetet és a telefont váltja ki." 2 gomb: sárga „Kipróbálom ingyen" (trial), outline „Nézd meg a demót" (görgetés a 5. szekcióra). Jobb oldalon a hős-lajhár mögött a **valódi foglaló-widget** a demo tenant Kerékcsere szolgáltatásával (Gumis állás, szombati slotok mono betűvel). | a widget élő komponens, nem kép |
| 2 | 3 fájdalompont | „Csörög a telefon, olajos a kezed" · „Elfelejtett időpont = üres állás" · „Gumiszezon: két hét káosz" — mindegyik alatt 1 mondat, hogyan oldja meg (online foglalás / SMS-emlékeztető / párhuzamos állások naptára) | Lucide ikonok, `brand-100` doboz |
| 3 | Hogyan működik — 3 lépés | „Felviszed az állásokat és a szerelőket" → „Kiteszed a linket a Google-cégprofilodba és a Facebookra" → „A vendég foglal, te csak megnézed a naptárat" | 3 screenshot a demo tenantból (determinisztikus seed → konzisztens) |
| 4 | Funkciók (6 blokk) | Állás-alapú naptár · Jóváhagyásos nagyobb munkák · Ajánlatkérés hibaleírással · SMS/e-mail emlékeztető · Szombati gumis nyitás külön munkarenddel · Statisztika (kihasználtság állásonként, szezon) | a szerviz nyelvén, nem általános feature-nevek |
| 5 | **Élő demo** | A böngésző-keret komponens `demo-autoszerviz`-re szűkítve, „Ügyfél nézet / Admin nézet" kapcsolóval, alatta caption: „Fiktív adatok · nem küld e-mailt, SMS-t · éjfélkor visszaáll". Mobilon screenshot + „Megnyitom a demót" gomb. | `docs/21` §2.1 technikai feltételei (CSP frame-ancestors, demo-login signed URL, sandbox iframe) változatlanul érvényesek |
| 6 | „Mit NEM csinál" | 3 rövid pont: nem munkalap, nem alkatrészkészlet, nem tételes számlázás — „ezekre ott a mostani rendszered; mi a naptárad vagyunk" | bizalomépítő őszinteség, kifejezetten kérés |
| 7 | Árazás (rövid) | a főoldali árazás-komponens, **Közepes csomag** kiemelve „Szervizeknek ajánlott" címkével (3 állás + 3 szerelő + branding + statisztika indoklással) | havi/éves kapcsoló megmarad |
| 8 | GYIK (5) | „Kell hozzá bankkártya a vendégnek?" (nem) · „Mi van, ha egy munka tovább tart?" (buffer + admin átütemezés) · „Több autója van egy vendégnek?" (a rendszámot a megjegyzésbe írja; strukturált mező érkezik — őszintén) · „Hogy kerül ki a linkem?" (Google-cégprofil, Facebook, QR a pultra) · „Mi van szombaton, ha csak a gumis dolgozik?" (külön munkarend) | accordion |
| 9 | Záró CTA | „Kezdd el ma, 5 perc. Nem kérünk bankkártyát." + sárga gomb + integető lajhár | mint a főoldalon |
| 10 | Footer | főoldali footer | — |

**SEO / mérés:**
- `<title>`: „Autószerviz időpontfoglaló rendszer online — slot4u"; meta description a hero leadből; H1 egyetlen; `schema.org/SoftwareApplication` + `FAQPage` JSON-LD a GYIK-ből; canonical; OG-kép a demo tenant screenshotjából.
- GA4/Clarity események a `docs/21` §2.1 készletével, plusz minden eseményen `vertical: 'autoszerviz'` paraméter; UTM-ek átadása a trial regisztrációs flow-nak (`utm_*` → `tenants.signup_source` vagy meglévő attribúciós mező — ha nincs ilyen, NE itt építsd, jelezd Linear-kommentben).
- Meta Pixel / Conversions API: a főoldalon használt beállítás öröklődik; `Lead` esemény a trial-regisztráció indításakor.

**Nem scope:** új design system, új illusztráció (a meglévő 3 lajhár-póz elég), blog/tartalom, más vertikálisok oldalai (de a sablon legyen rájuk kész: a persona-specifikus szövegek egy `content/verticals/autoszerviz.{json|md}` fájlból jöjjenek, ne a komponensbe égetve).

---

## 5. Lefedettségi mátrix — kiegészítés a `docs/20`-hoz

| Képesség | Autószerviz |
|---|---|
| Méret | több dolgozós |
| duration_based | ✔ (egész napos leadás, **staff + room metszet** — SLO-200) |
| no_time_slot | — |
| event_based + várólista | — |
| resource_rental | — (az állás nem bérelhető közvetlenül; a room a duration_based-en keresztül foglalódik) |
| manual_approval | ✔ (műszaki vizsga, vezérműszíj) |
| quote_request | ✔ (nagyobb javítás, üzenetváltással) |
| Multi-location | — |
| Online fizetés + számla | — |
| Branding testreszabás | ✔ (nem-wellness arculat) |
| Statisztika „wow" | ✔ (szezonalitás, kihasználtság állásonként) |
| Manager/Employee szerep demó | ✔ (szervizfogadó) |
| Üzenetküldés | ✔ (quote-on) |
| Szolgáltatás-szintű notes súgó | ✔ (új, kicsi) |

---

## 6. Linear issue-bontás

Egy issue = egy PR, a CLAUDE.md flow szerint. Mind a három issue leírása önállóan is elég a munkához, de a **docs az igazság forrása** — eltérésnél a docs nyer, az eltérést Linear-kommentben jelezni.

- **A — SLO-197 — Persona seed: „Csavarkulcs Autószerviz"** (M9, Medium). ⚠️ **Blokkolja: SLO-200** (automatikus erőforrás-kiosztás) — enélkül a persona fő értékajánlata nem demózható. Tartalmazza a `docs/20` mátrix-kiegészítést, a CLAUDE.md doksi-tábla sorát és a `notes_hint` kis feature-t (ma nem létezik). Tesztek: `docs/20` §3.5 invariánsok + staff∩room elérhetőségi teszt szombatra.
- **B — SLO-198 — Vertikális landing `/autoszerviz`** (M9, Low). Blokkolja: SLO-197 és SLO-192. Sablon-elv: a persona-tartalom külön content-fájlban.
- **C — SLO-199 — Custom fields minimál szelet (booking-szintű, szolgáltatáshoz kötött mezők)** (P2, Medium). Kapcsolódik: SLO-60, SLO-197. Elkészültekor A seedje frissül (külön kis issue akkor).
- **D — SLO-200 — Automatikus erőforrás-kiosztás `duration_based`-nél** (M9, High). **Az SLO-197 blokkolója**, és önállóan is termékhiány — l. az 1. fejezet dobozát.

---

## 7. Üzletfejlesztői megjegyzések (Daniel figyelmébe)

1. **Ez az első nem-wellness vertikális — mérőpont.** Ha a `/autoszerviz` landing Meta-hirdetésből olcsóbb trial-regisztrációt hoz, mint a főoldal, az a jel, hogy a vertikális landing-sablon a fő növekedési eszköz, és jöhet a többi 4 vertikális oldal ugyanabból a sablonból.
2. **Az őszinteség itt konverziós eszköz.** A „Mit NEM csinál" szekció szokatlan, de a szerviz-tulajdonos pontosan attól fél, hogy egy „mindent tudó" szoftvert kell megtanulnia. A „csak a naptárad vagyunk" üzenet a belépési küszöböt csökkenti.
3. **Terjesztési csatorna:** a szervizek 90%-ának a Google-cégprofil az egyetlen online felülete — a „Foglalás" gomb oda köthető linkje (M-szintű integráció a docs/08-ban) ennél a vertikálisnál a legerősebb érv; érdemes a GYIK-ben és a 3. lépésben hangsúlyozni.
4. **Ár-érzékenység:** a szerviz kisebb kosárértékkel dolgozik, mint a rendezvényház, de sokkal több foglalással — a Közepes csomag indoklását ne a feature-listára, hanem a „3 állás + szombati gumis munkarend + statisztika" konkrét igényre építsük.
