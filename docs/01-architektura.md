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
- Egyedi domain CNAME-mel (`booking.functionalfit.hu`) — `tenant_domains` tábla, `feature_custom_domain` feature flag.
- `IdentifyTenant` middleware: subdomain → tenant betöltés → container singleton + global scope aktiválás.
- Minden tenant-tulajdonú modell: `BelongsToTenant` trait (creating eventnél tenant_id kitöltés, global scope szűrés).
- Tenant státuszok: `trial`, `active`, `suspended` (lejárt fizetés — csak olvasás/figyelmeztető oldal), `archived` (soft delete, 90 nap megőrzés GDPR szerint).
- Storage: tenantonként prefixelt mappa (`storage/tenants/{id}/...`), publikus asset-ek (logó) külön diskre.

## Middleware lánc (tenant route-okon)

```
IdentifyTenant → EnsureTenantActive → EnsureFeatureEnabled:{feature} → can:{permission}
```

**M1-ben megvalósítva:** az első három láncszem. A `routes/tenant.php` minden route-ja az
`identify.tenant` → `ensure.tenant.active` aliasokon megy keresztül (SLO-10); a feature-kapuzás az
`ensure.feature:{feature}` aliassal opcionálisan ráhúzható egy route-ra (SLO-13). A `can:` (spatie)
gate az erőforrás-végpontokkal (M2) kerül be.

- `EnsureFeatureEnabled` (`ensure.feature:{feature}`): a megadott feature-kódot a Pennant az aktuális
  tenantra oldja fel (`FeatureServiceProvider` + `FeatureResolver`: `tenant_features` felülírás →
  `plan_features` default). Kikapcsolt vagy ismeretlen feature → `abort(403)` lang-üzenettel
  (`errors.feature_disabled`) — a képesség egyszerűen nincs bekapcsolva, nem rejtett, ezért 403 (nem 404).
  A frontend a tenantra engedélyezett kódokat az Inertia `features` shared propból kapja
  (`useFeatures()`/`feature()` helper). A Pennant store `array` (per-request feloldás a saját
  authoritatív tábláinkból, nincs külön elavuló cache).

- `IdentifyTenant`: a `{tenant}` subdomain-paraméterből keresi a tenantot. Foglalt label
  (`config('tenancy.reserved_subdomains')` + `admin_subdomain`) vagy nem létező/archivált (soft-deleted)
  slug → `abort(404)` (a cross-tenant próbálkozás létezést sem szivárogtat). Találat → `TenantManager`
  singletonba kötés + `app()->setLocale($tenant->locale)` (timezone NEM — UTC marad, csak megjelenítéskor).
- `EnsureTenantActive`: `trial`/`active` → tovább; `suspended` → `Tenant/Suspended` Inertia státuszoldal
  **503**-mal; `archived` → 404 (defenzív, a lookup amúgy is elbukik).
- A `tenant_id` izoláció a `BelongsToTenant` traiten keresztül (`app/Models/Concerns/`): global scope
  (`TenantScope`) szűr a `TenantManager` aktuális tenantjára, `creating` eventnél auto-kitölti a
  `tenant_id`-t. Tenant nélküli kontextusban (konzol, seeder, superadmin, queue) **no-op**. Egy modellt
  egyetlen sorral teszünk tenant-tulajdonúvá: `use BelongsToTenant;`. A `User` szándékosan NEM használja
  (megtörné a superadmint és a login-lookupot).

### Lokális fejlesztés — wildcard dev DNS

A központi domain `APP_CENTRAL_DOMAIN=slot4u.test` (`.env`). A séma:

| Host | Felület |
|---|---|
| `slot4u.test` | központi (apex) Welcome |
| `admin.slot4u.test` | superadmin panel |
| `{slug}.slot4u.test` | tenant felület (pl. `acme.slot4u.test`) |

Az nginx már wildcardol (`*.slot4u.test`), de a Windows/WSL `hosts` fájl nem. Két lehetőség:

1. **Statikus hosts bejegyzések** (Windows `C:\Windows\System32\drivers\etc\hosts`):
   ```
   127.0.0.1 slot4u.test admin.slot4u.test acme.slot4u.test suspended-demo.slot4u.test
   ```
2. **dnsmasq** (wildcard, ha sok tenant kell): `address=/slot4u.test/127.0.0.1`.

A `SESSION_DOMAIN=.slot4u.test` (vezető pont) megosztja a session cookie-t a subdomainek közt
(egyszeri bejelentkezés; a bejelentkezett user tenant-szűrése policy-kérdés, nem session).

**Demo tenantok** (`TenantDemoSeeder`, `make fresh` után): `acme` (active → tenant home),
`suspended-demo` (suspended → 503 státuszoldal). Tenant-admin loginok: `admin@acme.test` /
`admin@suspended-demo.test`, jelszó `password`.

## Mappastruktúra (lényegi részek)

```
app/
  Actions/            # egy-célú üzleti műveletek (CreateBooking, ApproveBooking...)
  Enums/              # BookingMode, BookingStatus, TenantStatus, BillingPeriodStatus, CommissionInvoiceStatus...
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
- Wildcard TLS `*.slot4u.hu` + egyedi domainekhez (`feature_custom_domain`) Let's Encrypt automatika (pl. Caddy vagy certbot DNS hook) — külön issue.
- Backup: napi DB dump + storage sync, visszaállási teszt negyedévente.
