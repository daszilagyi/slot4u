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
marad, a tenant saját tartalmából; a képeket Daniel a `temp/lelekut/` alá másolja (a design-projektből a 256 KB-os
export-korlát miatt nem tölthetők le).

| Mi | Hol |
|---|---|
| Sablon + tartalom | `tenants.landing` JSON oszlop (**nem** a `settings`-ben: az `UpdateTenantSettings` a teljes settings JSON-t újraírja az ismert kulcsokból, és törölné). `App\Settings\TenantLanding` szanitizál: ismeretlen sablon → `default`, listák korlátozva, nem-string elhagyva, ismeretlen ikon → levél. |
| Oldal | `resources/js/components/tenant-landing/CalmLanding.tsx`, a `Tenant/Home` választja, ha `landing.template === 'calm'`. `PublicLayout bare`: a DEMO-sáv, a skip link, a márkaszín és a cookie-sáv marad, a fejlécet és a láblécet a sablon hozza. |
| Adatforrás | **Valódi adat:** szolgáltatások (ár, időtartam, `requires_approval` → „Visszaigazolást igényel"), cím, telefon, e-mail, nyitvatartás. **Tenant-tartalom:** szlogen, kiemelések, buborékok, „Miért minket", Rólunk, vélemények, GYIK. **Lang (`tenant.calm.*`):** a sablon feliratai. Üres tartalomnál a szekció nem jelenik meg. |
| Arculat | `.theme-calm` tokenek (`resources/css/app.css`), self-hostolt **Lora** + **Manrope** (+ Caveat), csak világos téma. |
| Képek | `calmArt.ts` slotjai: `hero`, `why`, `portrait`, `about`, `contact`. Amíg nincs fájl, a szekció a design szerinti lágy háttérformát rajzolja. Beépítés: fájl a `resources/images/calm/` alá, import a `calmArt.ts`-be. |
| Demo | `PsychologistDemoPersona::landing()` tölti ki a Lélekút szövegeit (prodon az éjszakai `demo:reset` után jelenik meg). |
| GDPR | A tenant-purge a `landing`-et is törli (a gyakorló neve, bemutatkozása, idézetek). |

**Eltérések a designtól:**
* **„Üzenetet küldök"** → `mailto:` a tenant e-mail-címére (üzenetküldés funkció még nincs, SLO-36).
* **GYIK, lemondás:** a design e-mail-linkes átütemezést ígért. Vendégként csak a visszaigazoló oldalon lehet lemondani, átütemezni fiókkal lehet, ezért a szöveg ennek megfelelő.
* **Footer:** Adatvédelem/ÁSZF a tenant jogi dokumentumaiból, sütibeállítások; közösségi ikonok nincsenek (nincs adat mögöttük).
* **A szolgáltatások sorrendje** a valódi katalógusé (név szerint), nem a design statikus sorrendje.

**Nem scope:** admin-felület a tartalom szerkesztéséhez, képfeltöltés a sablonhoz.

