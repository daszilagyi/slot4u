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

### SEO assetek (SLO-89)

Tenant-scoped, gépi (nem lokalizált UI) SEO végpontok a publikus felülethez, mind a bekötött tenant `BelongsToTenant` global scope-ján keresztül szűrve (`App\Http\Controllers\Tenant\SeoController`):

- `GET /sitemap.xml` — XML sitemap a homepage-re, a `/book` belépőre és tenantonként az **aktív** szolgáltatások `/book?service={id}` mélylinkjeire (egyetlen lekérdezés, N+1-mentes). Az `ensure.tenant.active` mögött (csak operatív tenant).
- `GET /robots.txt` — az `ensure.tenant.active`-en KÍVÜL, csak `identify.tenant` mögött, hogy egy **suspended/archived** tenant is választ adjon: ilyenkor `Disallow: /`. Operatív tenantnál engedi a publikus felületet, tiltja a `/my/`, `/dashboard`, `/settings` területet, és a sitemapre mutat.
- `GET /og-image.png` — dinamikus, márka-színű 1200×630 Open Graph PNG **fej nélküli böngésző nélkül**, tiszta PHP GD-vel (a dev WSL-ben nincs Chrome): a tenant neve a beágyazott Inter fontból, kontraszt-tudatos szövegszínnel, opcionális logóval. A `public` diskre cache-elve (`og/{id}-{hash}.png`), a hash a branding-inputokból (név + primary color + logó) képződik; a homepage `og:image` a `?v={hash}` cache-buster query-vel hivatkozza, így rebrand friss fájlt ad. Lustán, első hívásra generálódik (`App\Services\Seo\OgImageGenerator`). A logó + primary color **`feature_branding`-gated** (docs/03, SLO-90/SLO-107): kikapcsolt feature mellett a kép a default színnel, logó nélkül renderel (a név marad, mert az cégprofil), és a gate-állapot a hashbe is beszámít, így be/kikapcsoláskor friss kép készül.

## Multi-tenancy modell

**Döntés: shared database + `tenant_id` + global scope.** (Alternatíva — tenantonkénti DB — elvetve: üzemeltetési teher, migrációk N-szer, cross-tenant statisztika nehéz. A superadmin statisztikákhoz és a központi számlázáshoz a shared modell egyszerűbb.)

- Tenant-azonosítás: subdomain (`functionalfit.slot4u.hu`). Központi marketing/regisztrációs oldal: `slot4u.hu`, superadmin: `admin.slot4u.hu`.
- Egyedi domain CNAME-mel (`booking.functionalfit.hu`) — `tenant_domains` tábla, `feature_custom_domain` feature flag. **Megvalósítva: SLO-42**, l. az „Egyedi tenant-domain" szakaszt lent.
- `IdentifyTenant` middleware: subdomain → tenant betöltés → container singleton + global scope aktiválás.
- Minden tenant-tulajdonú modell: `BelongsToTenant` trait (creating eventnél tenant_id kitöltés, global scope szűrés).
- Tenant státuszok: `trial`, `active`, `suspended` (lejárt fizetés — csak olvasás/figyelmeztető oldal), `archived` (soft delete, 90 nap megőrzés GDPR szerint).
- Storage: tenantonként prefixelt mappa (`storage/tenants/{id}/...`), publikus asset-ek (logó) külön diskre.

## Egyedi tenant-domain (SLO-42)

A tenant a **saját domainjén** (`foglalas.cegem.hu`) is kiszolgálhatja a publikus foglalófelületét, a `feature_custom_domain` mögött. A kanonikus `{slug}.{central}` aldomén **soha nem szűnik meg** — mindkét host ugyanazt szolgálja ki.

**Miért host-átírás, és nem második route-csoport?** A route-tábla a `{tenant}.{central}` domain-mintára van kulcsolva. A `routes/tenant.php` második regisztrálása egy catch-all domain alatt minden route-nevet duplikálna (a névfeloldás az utoljára regisztráltra állna), így két, egymástól elcsúszó kódútvonal keletkezne. Helyette egy **globális** (routing előtti) middleware, a `ResolveCustomDomain` a bejövő hostot a tenant kanonikus aldoménjére írja át — kizárólag a route-illesztés kedvéért. Minden ezután következő réteg (`IdentifyTenant`, a teljes middleware-lánc, a policy-k) változatlanul fut.

- **Feloldás:** `CustomDomainResolver` — egy indexelt egyenlőség-lekérdezés a `tenant_domains.domain`-re, kérésen belül memoizálva, kérések között cache-elve (`tenancy.resolution_ttl`, default 300s). **Csak a találatot cache-eljük**: a nem-találat cache-elése tetszőleges Host headerrel korlátlan cache-kulcsot engedne létrehozni (prodban DB cache store). Minden domain-írás (hozzáadás, verifikáció, elsődlegessé tétel, törlés) explicit `forget()`-et hív.
- **Látszat:** az átírás előtt `URL::forceRootUrl($request->getSchemeAndHttpHost())` — az `url()`-lel generált abszolút linkek (OG, sitemap, redirect) a látogató által használt hoston maradnak. A frontend relatív útvonalakat használ (nincs Ziggy), így az onnan induló navigáció eleve a helyén marad.
- **Nem szolgál ki semmit** az a host, amelyik ismeretlen, **nincs verifikálva**, vagy a tenantjától elvették a feature-t: ilyenkor a middleware nem nyúl a hosthoz, az egyetlen route-csoporthoz sem illeszkedik, és **404** lesz belőle. Az aldomén ettől függetlenül él.
- **Tulajdonjog igazolása:** DNS TXT rekord a `_slot4u-verify.{domain}` néven, értéke a soronkénti `verification_token`. Azért TXT és nem „ránk mutat-e a DNS": aki a zónába rekordot tud tenni, az jogosult a nevet ide irányítani. A `DnsResolver` interface a külvilág felé az egyetlen varrat (tesztben fake).
- **Session cookie:** a `SESSION_DOMAIN` `.{central}` (hogy egy session átfogja a tenant-aldoméneket), ami az egyedi domainen **kívül esik** — a böngésző el sem küldené a sütit, tehát nem lenne session, nem lenne CSRF token, és minden POST 419-cel bukna. A `ResolveCustomDomain` ezért egyedi hoston `session.domain`-t `null`-ra állítja (a StartSession előtt fut), így a süti pontosan arra a hostra kötődik. Következmény: az aldomén és az egyedi domain **külön session** — ez a helyes viselkedés, két különböző origin.
- **A teljes tenant-felület elérhető** az egyedi domainen, nem csak a publikus rész (admin panel, members area is) — a host-átírás után ugyanaz a route-tábla fut. Külön korlátozás nincs: a jogosultsági lánc változatlanul véd, a session pedig hostonként külön.
- **Sitemap/robots:** szándékosan a *kérés* hostján maradnak (`url()`), nem az elsődlegesen — a sitemap akkor hiteles, ha ugyanazon a hoston van, mint a benne listázott URL-ek. A duplikációt a canonical tag rendezi, nem a sitemap.
- **Elsődleges domain:** tenantonként legfeljebb egy, és csak verifikált lehet az. A `TenantPublicUrl` ezt adja vissza minden **általunk generált** linkhez (emailek, canonical tag, megosztási URL); enélkül az aldomént. Emiatt a publikus oldal `<link rel="canonical">`-ja az elsődleges hostra mutat, hogy a két host ne versenyezzen duplikált tartalomként. **301 átirányítás nincs**: ha a tenant DNS-e vagy TLS-e elromlik, a publikus felület az aldoménen továbbra is elérhető marad.

### Custom hostname TLS — DÖNTÉS: Cloudflare for SaaS (2026-07-27)

Az egyedi domainek tanúsítványát a **Cloudflare for SaaS (Custom Hostnames)** intézi az edge-en, nem a cPanel AutoSSL. Indok: az alternatíva (minden ügyfél-domaint kézzel alias-domainként felvenni cPanelben) tenantonként manuális onboarding-lépés, ami pont azt a self-service folyamatot töri el, amit az SLO-42 épített. A Cloudflare-változat **Free/Pro/Business csomagon 100 custom hostname-ig ingyenes**, felette 0,10 USD/hostname/hó, pay-as-you-go maximum 50 000 hostname — a bevezetési fázisban tehát költségmentes.

Amit ez a felállás megkövetel:

1. **Fallback origin:** egy hostname a `slot4u.hu` zónában (pl. `customers.slot4u.hu`), ami a Tárhely.Eu originra mutat. A Cloudflare minden custom hostname forgalmát ide irányítja.
2. **`APP_CUSTOM_DOMAIN_TARGET=customers.slot4u.hu`** — ezt kapja a tenant CNAME-célként az admin UI-ban (`tenancy.cname_target`).
3. **Hostname-regisztráció a Cloudflare API-n** (`POST /zones/{zone}/custom_hostnames`) minden verifikált tenant-domainre, és törlés a domain elengedésekor. **Enélkül a CNAME-elt domain 1014-es hibát ad** („CNAME cross-user banned") — a Cloudflare csak a nála regisztrált custom hostname-eket szolgálja ki. Ez még nincs megvalósítva → **SLO-135**.
4. **Cert-validáció:** mivel a domain már a Cloudflare-re CNAME-el, a HTTP-validáció automatikus; a tanúsítvány állapotát (`pending_validation` → `active`) az API adja vissza, ezt a `tenant_domains` soron érdemes megjeleníteni.

> A slot4u saját TXT-alapú tulajdonjog-igazolása (`_slot4u-verify.*`) **ettől független és megmarad**: az azt dönti el, hogy a nevet ide szabad-e irányítani, a Cloudflare-regisztráció pedig azt, hogy ki is tudjuk szolgálni. A kettő sorrendje: TXT-verifikáció → Cloudflare custom hostname létrehozása.

## Middleware lánc (tenant route-okon)

```
IdentifyTenant → EnsureTenantActive → [auth] → EnsureUserBelongsToTenant → EnsureFeatureEnabled:{feature} → can:{permission}
```

**M1-ben megvalósítva:** a publikus láncszemek (SLO-10) + az auth-guard (SLO-75) + a feature-kapu
(SLO-13). A `routes/tenant.php` publikus route-jai az `identify.tenant` → `ensure.tenant.active`
aliasokon mennek; a hitelesített tenant-terület (`/dashboard`) ezeken túl `auth` → `ensure.user.tenant`
mögött van. A feature-kapuzás az `ensure.feature:{feature}` aliassal opcionálisan ráhúzható. A `can:`
(spatie) gate az erőforrás-végpontokkal (M2) kerül be.

**Middleware-prioritás — scope-olt route-model binding (SLO-16):** a tenant-lánc (`IdentifyTenant` →
`EnsureTenantActive` → `auth` → `EnsureUserBelongsToTenant`) a `bootstrap/app.php` `priority()`
listájában a `SubstituteBindings` **elé** van rögzítve. Így mire a route-model binding lefut, a
`TenantManager` már fel van töltve, és a `BelongsToTenant` global scope a kötést az aktuális tenantra
szűkíti — egy másik tenant `{id}`-ja `404`-et ad (nem szivárog). E rögzítés nélkül a `SubstituteBindings`
a tenant-feloldás előtt futna, és a scope üresen (mindent látva) engedné a keresztbe-kötést.

**Auth és domainek (SLO-75/76):** Laravel Fortify (headless) adja a login/logout/jelszó-reset/email-
verifikáció backendet; a nézetek saját Inertia React oldalak (`Auth/*`, i18n a lang fájlokból). A
self-service **regisztráció** (SLO-76) a központi oldalon megy: a `CreateNewUser` action egy tranzakcióban
hozza létre a tenantot (`status=trial`, `trial_ends_at=+14 nap`), az admin usert (`tenant_id` az új
tenantból, SOHA a request-inputból) és ad neki tenant-admin role-t; a slug egyedi + nem foglalt
(`reserved_subdomains` + admin). A 14 nap leteltével a `tenants:expire-trials` ütemezett parancs
`trial → active`-ra vált (nincs lefokozás, docs/03). A session-cookie a központi domain + összes
subdomain közt megosztott (`SESSION_DOMAIN=.{central}`). Login/regisztráció után a `LoginResponse` /
`RegisterResponse` (közös `RedirectsToUserHome`) domain-tudatosan irányít: super-admin → `admin.{central}`,
tenant-user → a saját `{slug}.{central}/dashboard`-ja (cross-origin esetben Inertia location-redirect).

- `EnsureUserBelongsToTenant` (`ensure.user.tenant`): az `auth` után fut. Super-admin → redirect az
  admin panelre (tenant-impersonation az SLO-14-gyel jön); másik tenant usere → `abort(403)`.
- `EnsureSuperAdmin` (`ensure.superadmin`): az admin panelt (`admin.{central}`) a platform super-
  adminokra (`tenant_id = null`) szűkíti; tenant-user → `abort(403)`.

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

- Backend: Laravel lang fájlok, `hu` default (`APP_LOCALE=hu`), struktúra felkészítve `en`-re. Új nyelv = csak `lang/{locale}/*.php` hozzáadása, kódváltozás nélkül.
- Frontend: a `lang/hu/app.php` katalógus JSON-ként megosztva Inertia shared props-on keresztül (`usePage().props.translations`), `t('super.tenants.title')` helper (pont-jelölés + `:token` paraméter-behelyettesítés, `resources/js/lib/i18n.ts`). A `locale` és `translations` shared prop **lazy** (záró closure), így a tenant-feloldás (locale-beállítás) UTÁN értékelődik ki — a tenant-locale oldalak a helyes katalógust kapják. Build-time nem fordítunk be szövegeket.
- **Locale-feloldás (SLO-9):** `tenant locale → user locale → app default`. Tenant subdomainen az `IdentifyTenant` a tenant `locale`-ját állítja be; tenant nélküli kontextusban (admin/központi domain) a `SetLocale` middleware a bejelentkezett felhasználó `users.locale` preferenciáját, ennek hiányában az app default-ot alkalmazza. A `users.locale` nullable (alapból a fallback dönt).
- **Hardcoded-string tilalom (CI-ben kényszerítve):** ESLint `no-restricted-syntax` szabály tiltja a JSX-ben a nyers, felhasználónak szóló szöveget (2+ betűs szó) — minden UI string a `t()` helperen át jön. Kivétel a `resources/js/components/ui/**` (shadcn primitívek).
- Tenant-szintű felülírható szövegek (email sablonok, visszaigazolások): DB-ben tárolt, kulcs+nyelv alapú `tenant_translations` később — MVP-ben elég a sablon-szerkesztő (lásd 04/értesítések).

## Környezetek és üzemeltetés

Két külön profil fut: a **dev/CI referencia-stack** (Docker Compose) és az **éles profil** (osztott cPanel tárhely, Tárhely.Eu). A kód mindkettőn azonos — az eltérés csak env/konfiguráció/deploy-tooling (SLO-125). A részletes deploy-runbook és a szerver-felmérés (SSH-hozzáféréssel) a **publikus repón kívül**, privát üzemeltetési jegyzetként él.

**Dev / CI (referencia-stack):**
- Docker Compose (PHP-FPM, nginx, MariaDB, Redis, Reverb, Horizon worker) — fejlesztésre és a stack-ekvivalencia igazolására.
- CI: GitHub Actions — Pint, Larastan, Pest, build.

**Éles profil (Tárhely.Eu osztott cPanel, `slot4u.hu`):**
- **PHP 8.4** — CLI-ban teljes útvonallal (`/opt/cpanel/ea-php84/root/usr/bin/php`; a default 8.2 a compose-deppekhez kevés).
- **Broadcast:** hosted, Pusher-protokollú szolgáltató (Pusher Channels, EU cluster) — `BROADCAST_CONNECTION=pusher`. A Reverb Pusher-kompatibilis, ezért az eventek/csatornák/Echo-kliens változatlanok; a frontend a `VITE_REVERB_*` értékeket build-időben kapja (`ws-eu.pusher.com:443`). Nincs self-hosted Reverb daemon (osztott tárhelyen nem nyitható port).
- **Queue:** `QUEUE_CONNECTION=database` + percenkénti cron worker `flock`-kal az átfedés ellen (`queue:work --stop-when-empty --max-time=55`). Nincs Horizon. Következmény: az utómunkák (email, webhook-utókezelés) worst-case ~1 perc késleltetésűek; a Barion-webhook **fogadása** szinkron HTTP, azt nem érinti.
- **Cache / session:** `CACHE_STORE=database`, `SESSION_DRIVER=database` (nincs Redis-szerver, csak kliens; a `phpredis` az `ea-php84`-en nincs). Redis-t a host később adhat → visszaállítható, nem blokkoló.
- **Mail:** `MAIL_MAILER=smtp` (`no-reply@slot4u.hu`, `smtps` séma a 465-ös porton — Laravel 11/12 `MAIL_SCHEME=smtps`, nem `MAIL_ENCRYPTION`); DKIM a cPanel Email Deliverability alatt.
- **TLS / aldomének:** DNS Cloudflare mögött, Universal SSL fedi a `*.slot4u.hu`-t az edge-en; origin felé cPanel cert. cPanel `*` wildcard aldomain → docroot az app `public/`-ja. Az egyedi tenant-domain (`feature_custom_domain`) app-oldala kész (SLO-42); a custom hostname TLS-ét **Cloudflare for SaaS** adja (100 hostname-ig ingyenes), a hostname-provisioning az SLO-135 — l. az „Egyedi tenant-domain" szakaszt.
- **Cloudflare mögötti IP/séma:** az Apache már visszaállítja a valódi klienst (mod_cloudflare), a Laravel `trustProxies` a CF-tartományokra ennek framework-szintű biztosítéka (rate limit + audit valódi kliens-IP-t, `X-Forwarded-Proto` HTTPS-sémát lát) — l. `bootstrap/app.php`.
- **SSR:** kikapcsolva indulunk (`INERTIA_SSR_ENABLED=false`, nincs Node daemon) — a SEO-hatás vállalt; külön spike, ha Passengerrel megoldható.
- **Deploy-sajátosságok:** `storage:link` helyett shell `ln -s` (a PHP `symlink()` tiltott); Vite build lokálisan/CI-ban, csak a build-output megy fel; scheduler cron `schedule:run` percenként.
- Backup: napi DB dump + storage sync, visszaállási teszt negyedévente.
