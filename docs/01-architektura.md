# 01 — Architektúra

## Stack összefoglaló

| Réteg | Választás | Indoklás |
|---|---|---|
| Backend | Laravel 12+, PHP 8.3+ | megrendelői kérés, érett ökoszisztéma |
| Frontend | Inertia.js v2 + React + TS | egy kódbázis adminra és publikus felületre, nem kell külön API réteg |
| UI | Tailwind 4 + shadcn/ui + Framer Motion | dark mode default, bento grid dashboard, fluid animációk |
| DB | MariaDB/MySQL | megrendelői kérés |
| Queue | Redis + Horizon | email, emlékeztetők, számlázás háttérben |
| Realtime | Laravel Reverb | élő foglalás-kártya az admin dashboardon |
| Auth | Laravel Fortify/Breeze alap + saját tenant logika | |

**Döntés:** a publikus foglalófelület NEM külön Astro/Next app, hanem ugyanaz a Laravel+Inertia alkalmazás publikus route-csoportja. SSR (Inertia SSR) bekapcsolva a publikus oldalakon SEO és sebesség miatt. Ha később mégis kell külön frontend, a Service réteg API-vá alakítható.

## Multi-tenancy modell

**Döntés: shared database + `tenant_id` + global scope.** (Alternatíva — tenantonkénti DB — elvetve: üzemeltetési teher, migrációk N-szer, cross-tenant statisztika nehéz. A superadmin statisztikákhoz és a központi számlázáshoz a shared modell egyszerűbb.)

- Tenant-azonosítás: subdomain (`functionalfit.slot4u.hu`). Központi marketing/regisztrációs oldal: `slot4u.hu`, superadmin: `admin.slot4u.hu`.
- Max csomag: egyedi domain CNAME-mel (`booking.functionalfit.hu`) — `tenant_domains` tábla.
- `IdentifyTenant` middleware: subdomain → tenant betöltés → container singleton + global scope aktiválás.
- Minden tenant-tulajdonú modell: `BelongsToTenant` trait (creating eventnél tenant_id kitöltés, global scope szűrés).
- Tenant státuszok: `trial`, `active`, `suspended` (lejárt fizetés — csak olvasás/figyelmeztető oldal), `archived` (soft delete, 90 nap megőrzés GDPR szerint).
- Storage: tenantonként prefixelt mappa (`storage/tenants/{id}/...`), publikus asset-ek (logó) külön diskre.

## Middleware lánc (tenant route-okon)

```
IdentifyTenant → EnsureTenantActive → EnsureFeatureEnabled:{feature} → can:{permission}
```

## Mappastruktúra (lényegi részek)

```
app/
  Actions/            # egy-célú üzleti műveletek (CreateBooking, ApproveBooking...)
  Enums/              # BookingMode, BookingStatus, PlanTier, TenantStatus...
  Models/
  Models/Concerns/BelongsToTenant.php
  Services/Booking/   # AvailabilityService + BookingModeStrategy implementációk
  Policies/
resources/js/
  Pages/Public/       # publikus foglalófelület (tenant subdomain)
  Pages/Admin/        # tenant admin (Inertia)
  Pages/Super/        # superadmin panel
  Pages/Members/      # ügyfél members area
  components/ui/      # shadcn
lang/hu/              # MINDEN UI szöveg innen
docs/                 # ez a dokumentáció
```

## i18n architektúra

- Backend: Laravel lang fájlok, `hu` default, struktúra felkészítve `en`-re.
- Frontend: lang fájlok JSON-ként megosztva Inertia shared props-on keresztül (`usePage().props.translations`), `t('booking.confirm')` helper. Build-time nem fordítunk be szövegeket.
- Tenant-szintű felülírható szövegek (email sablonok, visszaigazolások): DB-ben tárolt, kulcs+nyelv alapú `tenant_translations` később — MVP-ben elég a sablon-szerkesztő (lásd 04/értesítések).

## Környezetek és üzemeltetés

- Saját hosting (nem self-hosted ügyfeleknél!): 1 produkciós környezet + staging.
- Docker Compose (PHP-FPM, nginx, MariaDB, Redis, Reverb, Horizon worker) — fejlesztésre és prod-ra is.
- CI: GitHub Actions — Pint, Larastan, Pest, build.
- Wildcard TLS `*.slot4u.hu` + Max csomag egyedi domainekhez Let's Encrypt automatika (pl. Caddy vagy certbot DNS hook) — külön issue.
- Backup: napi DB dump + storage sync, visszaállási teszt negyedévente.
