Use the claude_design MCP (https://api.anthropic.com/v1/design/mcp, auth via /design-login) to import this project:
https://claude.ai/design/p/43c597aa-a3c4-4aa2-ac56-648defffc0ee?file=GlamZone+Landing.dc.html

Focus on these files (the whole project is readable):
- `GlamZone Landing.dc.html`

Also read these files the selection imports:
- `assets/hero-sloth.png`
- `assets/logo-flower.png`
- `assets/sloth-dryer.png`
- `assets/sloth-phone.png`
- `image-slot.js`
- `support.js`

Implement: `GlamZone Landing.dc.html`

---

## Megvalósítás (SLO-241, 2026-09-14)

Újrahasználható **„glam" tenant landing-sablon**, az SLO-238 calm sablon mintájára (`docs/25`): egy
szalon csapattal, sötét háttérrel és rózsaszín neonnal. A képeket Daniel a `temp/glamzone/` alá
másolta. A design-projekt állapotfájlja 256 KB-nál csonkolva jön vissza, ezért csak a 4 kategóriakép
hozzárendelése volt kiolvasható. A többi fotót ehhez igazítottam.

| Mi | Hol |
|---|---|
| Sablon | `LandingTemplate::Glam`; a `Tenant/Home` a `GlamLanding.tsx`-et rajzolja, ha `landing.template === 'glam'`. `PublicLayout bare`: a DEMO-sáv, a skip link és a cookie-sáv marad. |
| Tartalom | `tenants.landing` (`App\Settings\TenantLanding`): `headline` (max. 3 sor, az első rózsaszín), `neon` (hero-tábla és a kapcsolat-sáv felirata), `bubbles[0]` (a hero matricája), `categories[]` / `featured[]` / `team[]` (név + alcím / jelvény / szakterület + `photo` kulcs), `quick_service`. A `photo` csak slot-kulcs lehet (`/^[a-z0-9][a-z0-9-]{0,39}$/`), útvonal vagy URL nem. |
| Valódi adat | Kategóriák, szolgáltatások (ár, időtartam, leírás), cím, telefon, nyitvatartás. **A tartalom csak megnevezi, mit emeljen ki**: a `categories`, `featured` és `team` elemeit **név szerint** párosítja a valódi rekordokhoz, a nem egyező elem kimarad. |
| Élő adat | `App\Services\Landing\GlamLandingData`, csak glam sablonnál számolva (`glam` prop, egyébként `null`): legfeljebb 3 **aktív** csapattag (név, titulus a `staff` táblából), és a `quick_service` első 4 szabad kezdési időpontja azon a napon, amelyik ma vagy a következő 7 napban elsőként kínál ilyet (`AvailabilityService::slotsForRange`, a múltbeli időpontok kiszűrve). Ha egyik sincs, a szekció nem jelenik meg. |
| Arculat | `.theme-glam` tokenek (`resources/css/app.css`), **csak sötét**. Self-hostolt **Playfair Display** (`@fontsource-variable/playfair-display`) a címekhez, Inter a szöveghez, Caveat a neonhoz. A neon lüktetése a reduced-motion alsó korlát alatt áll. |
| Képek | `glamArt.ts`: fix slotok (`hero`, `phone`, `dryer`, `mark`, `salon`) és a tartalom által megnevezett fotók. Fájlok: `resources/images/glam/`, a forrásokat és a feldolgozást a mappa `README.md`-je rögzíti. **A portrék csak fiktív demo-dolgozókhoz használhatók.** |
| Demo | `SalonDemoPersona::landing()` (prodon az éjszakai `demo:reset` után jelenik meg). A `SalonPersonaTest` őrzi, hogy minden megnevezett szolgáltatás, kategória és a gyors szolgáltatás létezik a seedben. |

**Eltérések a designtól:**
* **Csapat:** a design Nóra / Lili / Eszter mintacsapata helyett a persona valódi dolgozói: Kovács Réka, Szabó Nóra, Kiss Dorina. A 4. dolgozónak (Tóth Bence) nincs kártyája, mert a sablon 3 fő mellé rajzolja a „Mindegy, kihez" kártyát, és nincs hozzá portré. Foglalható, az „Összes szakember" linken elérhető.
* **Kategóriák:** a design 4 kártyája (Haj, Kozmetika, Kéz & köröm, Lábápolás) helyett a 3 valódi kategória (Fodrászat, Kozmetika, Kéz és láb). 3 kártyánál a rács 3 oszlopos.
* **„Van 45 perced magadra?"** → a valódi szolgáltatás időtartama („Van 30 perced magadra?", Hajmosás + szárítás). A szöveg alatt a nap a valóság szerint: ma, holnap vagy a legközelebbi nap.
* **Nyitvatartás:** a tenant profiljának egysoros szövege, nem a design táblázata.
* **Térkép:** dekoratív minta tűvel, a Google Maps keresésre linkel. Valódi térképet nem ágyazunk be (CSP, süti).
* **Téma-kapcsoló:** nincs, a sablon egyetlen sötét kinézet.
* **Footer:** a tenant jogi dokumentumai, a Kapcsolat és a Sütibeállítások; közösségi linkek csak a profilban megadott Instagramra és Facebookra. A „demo landing page" jogi megjegyzés csak demo tenanton látszik.
* **Lajhár-kép a sablonban:** a hero lajhárján GlamZone-feliratos köpeny van. Valódi tenantnak saját kép kellene; képfeltöltés a sablonhoz nem scope.
* **Töréspontok:** a design 1440-es. A kétoszlopos hero, a 4 oszlopos rácsok és az asztali fejléc `xl`-től él, alatta tablet/mobil elrendezés (az SLO-239 tanulsága).

**Nem scope:** admin-felület a tartalomhoz, képfeltöltés a sablonhoz, világos téma.

