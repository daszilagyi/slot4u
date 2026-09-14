Use the claude_design MCP (https://api.anthropic.com/v1/design/mcp, auth via /design-login) to import this project:
https://claude.ai/design/p/3122753c-97a8-468f-afad-6ce0831d228c?file=Lelekut+Landing.dc.html

Focus on these files (the whole project is readable):
- `Lelekut Landing.dc.html`

Also read these files the selection imports:
- `image-slot.js`
- `support.js`

Implement: `Lelekut Landing.dc.html`

---

## Megvalósítás (SLO-238, 2026-09-13)

**Daniel döntései:** újrahasználható **„calm" tenant landing-sablon** (nem egyszeri demo-oldal); a vélemény-szekció
marad, a tenant saját tartalmából; a képeket Daniel a `temp/lelekut/` alá másolta (a design-projektből a 256 KB-os
export-korlát miatt nem tölthetők le); a budapesti utcakép nincs felhasználva (a designban nincs helye).

| Mi | Hol |
|---|---|
| Sablon + tartalom | `tenants.landing` JSON oszlop (**nem** a `settings`-ben: az `UpdateTenantSettings` a teljes settings JSON-t újraírja az ismert kulcsokból, és törölné). `App\Settings\TenantLanding` szanitizál: ismeretlen sablon → `default`, listák korlátozva, nem-string elhagyva, ismeretlen ikon → levél. |
| Oldal | `resources/js/components/tenant-landing/CalmLanding.tsx`, a `Tenant/Home` választja, ha `landing.template === 'calm'`. `PublicLayout bare`: a DEMO-sáv, a skip link, a márkaszín és a cookie-sáv marad, a fejlécet és a láblécet a sablon hozza. |
| Adatforrás | **Valódi adat:** szolgáltatások (ár, időtartam, `requires_approval` → „Visszaigazolást igényel"), cím, telefon, e-mail, nyitvatartás. **Tenant-tartalom:** szlogen, kiemelések, buborékok, „Miért minket", Rólunk, vélemények, GYIK. **Lang (`tenant.calm.*`):** a sablon feliratai. Üres tartalomnál a szekció nem jelenik meg. |
| Arculat | `.theme-calm` tokenek (`resources/css/app.css`), self-hostolt **Lora** + **Manrope** (+ Caveat), csak világos téma. |
| Képek | `calmArt.ts` slotjai: `hero`, `why`, `portrait`, `about`, `contact`, `mark` (levél-logó), `leaves` (díszítés). A fájlok a `resources/images/calm/` alatt, forrásukat és a feldolgozást (beégetett sakktábla-háttér és szövegbuborék eltávolítva) a mappa `README.md`-je rögzíti. Slot fájl nélkül: a szekció a lágy háttérformát rajzolja. A „miért” buborék szövege a tenant tartalma, nem a képé. A portré és a „Rólunk” fotó Pexels-kép (MART PRODUCTION, 7699304 és 7699458). A licenc nem kér feltüntetést, a forrás a README-ben szerepel. A portré csak a fiktív demo gyakorlóhoz használható, valódi tenant munkatársának arcaként nem. |
| Demo | `PsychologistDemoPersona::landing()` tölti ki a Lélekút szövegeit (prodon az éjszakai `demo:reset` után jelenik meg). |
| GDPR | A tenant-purge a `landing`-et is törli (a gyakorló neve, bemutatkozása, idézetek). |

**Eltérések a designtól:**
* **„Üzenetet küldök"** → `mailto:` a tenant e-mail-címére (üzenetküldés funkció még nincs, SLO-36).
* **GYIK, lemondás:** a design e-mail-linkes átütemezést ígért. Vendégként csak a visszaigazoló oldalon lehet lemondani, átütemezni fiókkal lehet, ezért a szöveg ennek megfelelő.
* **Footer:** Adatvédelem/ÁSZF a tenant jogi dokumentumaiból, sütibeállítások; közösségi ikonok nincsenek (nincs adat mögöttük).
* **A szolgáltatások sorrendje** a valódi katalógusé (név szerint), nem a design statikus sorrendje.
* **Töréspontok (SLO-239):** a design két nézetet ad (1440 és 390). Az asztali elrendezés (fejléc-menü, kétoszlopos hero, motto-keret, második buborék, 4 oszlopos kártyarács, kétoszlopos „Miért” és „Rólunk”) `xl`-től (1280) él, alatta a tablet-elrendezés marad: a fix pixeles mértékek 1024-en egymásra csúsztak. A motto-keret 1400 px alatt 90 px-re áll a jobb szélről (150 helyett), különben 1280-on rátakar az első buborékra.

**Nem scope:** admin-felület a tartalom szerkesztéséhez, képfeltöltés a sablonhoz.

