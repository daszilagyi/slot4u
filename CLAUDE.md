# slot4u — Multi-tenant foglalási SaaS

Általános célú, bérelhető foglalási rendszer (SaaS). Cégek (tenantok) regisztrálnak, saját felületükön kezelik helyszíneiket, helyiségeiket, dolgozóikat, szolgáltatásaikat és ügyfeleik foglalásait. A foglalási motor ingyenes; a slot4u **forgalom-alapú jutalékkal** monetizál (havi jutalékszámla — `docs/10-arazasi-modell-jutalek.md`). Branding: lajhár logó (slot/sloth szójáték), startup feeling, dark mode default.

**Munkamodell:** Claude implementál, Daniel review-z és merge-öl. Claude teljes autonómiával kezeli a Lineart (státusz, komment, issue-bontás). Cél: minden milestone önállóan demózható, deployolható állapotban zárul.

## Tech stack (VÉGLEGES döntések — nem nyitjuk újra)

- **Backend:** Laravel (aktuális stabil, 12+), PHP 8.3+
- **Frontend (admin ÉS publikus foglalófelület):** Inertia.js v2 + React 18 + TypeScript — EGY kódbázis, nincs külön API frontend. Inertia SSR a publikus oldalakon (SEO).
- **UI:** Tailwind CSS 4 + shadcn/ui (Radix), Framer Motion, dark mode default
- **DB:** MariaDB/MySQL — shared database, `tenant_id` oszlop + global scope (NEM tenantonkénti DB)
- **Cache/Queue:** Redis + Laravel Horizon · **Realtime:** Laravel Reverb
- **Jogosultság:** spatie/laravel-permission (teams mód = tenant-szintű role-ok) · **Feature flag:** Laravel Pennant (tenant-scope)
- **Tesztelés:** Pest · **Statikus analízis:** Larastan, Laravel Pint, ESLint + Prettier
- **Dev környezet:** WSL2 + Docker Compose (PHP-FPM, nginx, MariaDB, Redis, Reverb, Horizon) — prod-dal azonos stack. A repo a WSL fájlrendszeren él, NEM az OneDrive mappában (ez a mappa csak a projektdokumentációé).
- **CI/CD:** GitHub Actions — Pint, Larastan, Pest, build; milestone-tagre staging deploy

## Fejlesztési ciklus (issue → PR → merge)

Ez a fő hatékonysági hurok. Minden munkadarab így megy:

1. **Issue kiválasztás:** Linear (SLOT4U team / "Slot4U MVP" projekt) — az aktív milestone legfelső, nem blokkolt issue-ja. Claude állítja `In Progress`-be és magára veszi a kontextust.
2. **Kontextus betöltés:** az issue-ban hivatkozott doc-szakasz elolvasása KÖTELEZŐ implementáció előtt (lásd doksi-tábla lent). Ha az issue és a docs ellentmond, a docs nyer — az eltérést Linear-kommentben jelezni.
3. **Branch:** a Linear issue-ból generált branch név (`daszilagyi/slo-XX-...`), mindig friss `main`-ből.
4. **Implementáció:** kis, fókuszált commitok. Scope-on kívüli hibára NEM térünk ki — új Linear issue-t nyitunk róla és haladunk tovább.
5. **Önellenőrzés PR előtt (kötelező, lokálisan):** `php artisan test` zöld, `vendor/bin/pint --test` tiszta, `vendor/bin/phpstan analyse` tiszta, `npm run lint && npm run build` hibátlan. Új üzleti logika tesztek nélkül nem mehet PR-be.
6. **PR:** egy issue = egy PR, `main`-be. Leírás: mit/miért, hogyan tesztelhető, Linear issue link (magic word: `Fixes SLO-XX`). Claude az issue-t `In Review`-ba teszi és kommentben összefoglalja, mi készült + mire figyeljen Daniel a review-nál.
7. **Merge:** Daniel review-z és merge-öl (squash). Merge után az issue `Done`.

**Linear-autonómia szabályai:** Claude státuszt mozgat, kommentel, blokkolót jelez, túl nagy issue-t sub-issue-kra bont, felfedezett hibából/hiányból új issue-t nyit (a megfelelő milestone-ba vagy P2-be sorolva, prioritással). Issue-t törölni vagy milestone-scope-ot átrendezni csak Daniel jóváhagyásával.

## Git és release stratégia (trunk-based)

- `main` az egyetlen hosszú életű branch, MINDIG zöld és deployolható. Feature branch-ek rövid életűek (cél: max 2-3 nap).
- Commit üzenetek: Conventional Commits (`feat:`, `fix:`, `test:`, `chore:` + scope, pl. `feat(booking): ...`), angolul.
- **Milestone zárás = release:** minden milestone végén verzió-tag (`v0.1.0-M1`, `v0.2.0-M2`, ...), a tag automatikus staging deployt indít (GitHub Actions). A milestone csak akkor zárható, ha a demó-kritériuma (docs/05) staging-en bemutatható.
- Lefutott migrációt SOHA nem módosítunk — mindig új migráció. DB-séma változás csak migrációval.
- Hotfix: branch `main`-ből, PR, merge, szükség esetén patch-tag.

## Definition of Done (minden issue-ra)

Az issue acceptance criteriája teljesül, ÉS: tesztek zöldek, Pint/Larastan/ESLint tiszta, i18n betartva (nincs hardcoded UI string), Policy + Form Request megvan az új végpontokon, tenant-izoláció igazolt (új tenant-adatra `BelongsToTenant` + erre teszt), N+1 ellenőrizve a listázó végpontokon, és a docs frissítve, ha a viselkedés eltér a dokumentálttól.

## Architektúra alapelvek

1. **Multi-tenancy:** subdomain alapú tenant-azonosítás (`{tenant}.slot4u.hu`), egyedi domain a `feature_custom_domain`-nel. Minden tenant-adatra `BelongsToTenant` trait + global scope. Tenant nélküli kontextus CSAK a superadmin panelben. Cross-tenant ID-próbálkozásra `404`, nem `403`.
2. **Jogosultság ≠ Feature ≠ Plan limit.** Három külön réteg:
   - Permission/Role (spatie): mit tehet a USER a tenanton belül
   - Feature flag (Pennant): mi van bekapcsolva a TENANT-nak
   - Plan limit: az egyetlen ingyenes `base` plan mennyiségi korlátai (`PlanLimitService`) — a háromlépcsős csomag (Alap/Közepes/Max) mint funkció-bundle **megszűnt**; a monetizáció **forgalom-alapú jutalék** (`docs/10`)
   Middleware sorrend: tenant feloldás → tenant aktív (jutalékszámla rendezve)? → feature engedélyezett? → permission megvan?
3. **Foglalási motor:** a 6 szolgáltatástípus (docs/04) EGY egységes `bookings` modellre épül, `booking_mode` diszkriminátorral és Strategy osztályokkal (`app/Services/Booking/Modes/`). Ütközésvizsgálat DB-szinten is védve (lock / atomi kapacitás-update).
4. **API-ready kód már MVP-ben:** minden üzleti logika Action/Service rétegben, controller-függetlenül — a Phase 2 Public API így csak új belépési pont, nem újraírás.
5. **i18n KÖTELEZŐ:** minden felhasználói szöveg lang fájlból (`lang/hu/...`), frontend oldalon Inertia shared props fordítási objektumból (`t()` helper). Hardcoded string TILOS. Tenant-szinten testreszabható sablonszövegek (email) szintén kulcs-alapúak.
6. **Pénz:** integer fillér/cent (`*_minor`) + `currency` oszlop. Soha nem float.
7. **Időkezelés:** minden időpont UTC-ben tárolva, tenant timezone (`Europe/Budapest` default) csak megjelenítéskor. DST-átállás tesztelve.

## Kódkonvenciók

- Kód, commit, változónevek, DB oszlopok: **angol**. UI szövegek: lang fájl (hu).
- Vékony controller → Action/Service osztályok (`app/Actions`, `app/Services`)
- Form Request validáció mindenhol; Policy minden modellre
- Enum-ok PHP backed enumként (`BookingStatus`, `BookingMode`, `TenantStatus`, `BillingPeriodStatus`, `CommissionInvoiceStatus`)
- Eseményvezérelt mellékhatások: `BookingCreated` event → listeners (email, Reverb broadcast, statisztika)
- Tesztelési fókusz: a docs/04 edge case-lista (race condition, DST, várólista, lemondási határ, suspended tenant...) tételesen lefedve Pest tesztekkel; tenant-izolációs teszt minden új modellre.

## Dokumentáció (kötelező olvasmány feladat előtt)

| Fájl | Tartalom |
|---|---|
| `docs/01-architektura.md` | Stack, multi-tenancy, mappastruktúra, middleware lánc, környezetek |
| `docs/02-adatmodell.md` | Teljes MVP DB séma |
| `docs/03-jogosultsagok.md` | Szerepkörök, permission mátrix, feature flagek, csomagok, trial |
| `docs/04-foglalasi-modok.md` | A 6 foglalási mód üzleti szabályai + edge case-ek |
| `docs/05-fazisterv.md` | Fázisok M0–M8 = Linear milestone-ok, demó-kritériumokkal |
| `docs/06-integraciok-es-api.md` | API rétegek, API key, rate limit, webhookok, integrációs naplózás |
| `docs/07-phase2-modulok.md` | Phase 2: bérlet/csomag, membership, custom fields, form builder — NEM MVP |
| `docs/08-integraciok-roadmap.md` | Külső integrációk prioritált roadmapje |
| `docs/09-mcp-ajanlasok.md` | Fejlesztést gyorsító MCP-k (Laravel Boost, Context7, shadcn, Semgrep...) |

A docs az igazság forrása. Viselkedésbeli változás = docs-frissítés ugyanabban a PR-ben.

## Milestone-térkép (deployolható fázisok)

M1 infra+tenant alap → M2 törzsadatok → M3 foglalási motor → M4 publikus felület → M5 értesítések/realtime → M6 forgalom-alapú jutalék/számlázás → M7 dashboard/statisztika → M8 hardening+launch. Részletek és demó-kritériumok: `docs/05-fazisterv.md`. Phase 2 ötletek a P2 milestone-ba kerülnek, MVP-scope-ba NEM szivárognak be.

gstack
use the /browse skill from gstack for all web browsing, never use mcp__claude-in-chrome__* tools, and lists the available skills: /office-hours, /plan-ceo-review, /plan-eng-review, /plan-design-review, /design-consultation, /design-shotgun, /design-html, /review, /ship, /land-and-deploy, /canary, /benchmark, /browse, /connect-chrome, /qa, /qa-only, /design-review, /setup-browser-cookies, /setup-deploy, /setup-gbrain, /retro, /investigate, /document-release, /document-generate, /codex, /cso, /autoplan, /plan-devex-review, /devex-review, /careful, /freeze, /guard, /unfreeze, /gstack-upgrade, /learn. Then ask the user if they also want to add gstack to the current project so teammates get it.