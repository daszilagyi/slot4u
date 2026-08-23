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
- Tenant státuszok: `trial`, `active`, `suspended` (lejárt fizetés — csak olvasás/figyelmeztető oldal), `archived` (soft delete, 90 nap megőrzés GDPR szerint, utána automatikus anonimizálás — `docs/19` §7).
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
3. **Hostname-regisztráció a Cloudflare API-n** (`POST /zones/{zone}/custom_hostnames`) minden verifikált tenant-domainre, és törlés a domain elengedésekor. **Enélkül a CNAME-elt domain 1014-es hibát ad** („CNAME cross-user banned") — a Cloudflare csak a nála regisztrált custom hostname-eket szolgálja ki. Megvalósítva: **SLO-135**, l. lent.
4. **Cert-validáció:** mivel a domain már a Cloudflare-re CNAME-el, a HTTP-validáció automatikus; a tanúsítvány állapotát (`pending_validation` → `active`) az API adja vissza, ez a `tenant_domains.certificate_status`-ba kerül.
5. ⚠️ **Host header:** a Cloudflare **a látogató saját hostnevét** küldi tovább Host headerként (`foglalas.cegem.hu`), amire az osztott tárhely `*.slot4u.hu` vhostja **nem illeszkedik** — a kérés a szerver default vhostjára esne, és a Full (strict) TLS is bukna, mert az origin certje nem fedi az ügyfél nevét. Ezt két Cloudflare-szabály oldja meg, l. lent.

### Hostname-provisioning (SLO-135)

**Tulajdonjog ≠ kiszolgálhatóság.** A TXT-verifikáció (SLO-42) azt dönti el, hogy a nevet ide szabad-e irányítani; a Cloudflare-regisztráció azt, hogy ki is tudjuk-e szolgálni. A kettő külön oszlopokban él (`provisioning_status`, `certificate_status`, `provider_hostname_id`), és **egy provider-hiba SOSEM vonja vissza a `verified_at`-et** — a tenant bizonyította, hogy övé a domain, ez akkor is igaz, ha a Cloudflare épp nem válaszol. A hiba a soron marad, látszik az adminban, és újrapróbálható (az SLO-133 számla-kiállítás mintája).

- **`CustomHostnameProvisioner`** interface + `CloudflareCustomHostnameProvisioner` + **`NullCustomHostnameProvisioner`**. Konfiguráció (`CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ZONE_ID`) híján a null implementáció megy, és **egyetlen kimenő hívás sem történik** — dev és CI így soha nem hív ki. Ilyenkor a domain `verified`, de `provisioning_status = null` („nem volt kísérlet"), nem `failed`.
- **Idempotencia:** a provisioning előbb *keres* (`GET ?hostname=`), és csak akkor hoz létre, ha a Cloudflare még nem ismeri — egy elszállt create utáni retry nem regisztrál kétszer. A hostname-szűrő egyes csomagokon prefix-illeszkedik, ezért a találat nevét ellenőrizzük is.
- **Tanúsítvány-figyelés:** a Cloudflare aszinkron állítja ki a certet és **nem hív vissza**, ezért a `domains:refresh-certificates` cron 10 percenként lekérdezi a `pending`/`failed` sorokat. Óránkénti futás mellett egy már működő domain fél órán át „még várakozik"-ot mutatna a tenantnak.
- **Elengedés:** a domain törlésekor a `DeprovisionCustomHostname` job **a provider id-t stringként** viszi, mert a `tenant_domains` sor ekkor már szándékosan nincs meg (a unique indexnek fel kell szabadítania a hostnevet a következő igénylőnek).

> **A két Cloudflare-szabály (dashboardon, nem API-ból):**
> 1. **Transform Rule** (Modify Request Header): `X-Slot4u-Original-Host` = `http.host` — a valódi látogatói hostot átviszi egy privát fejlécbe.
> 2. **Origin Rule** (Host Header): `customers.slot4u.hu` — a Host headert (és ezzel az SNI-t) a fallback originre írja át, így a `*.slot4u.hu` vhost illeszkedik és az origin cert is érvényes.
>
> *(A két szabály helyettesíthető egyetlen Workerrel, ami ugyanezt a két dolgot teszi — a lényeg, hogy az eredeti hoszt átkerüljön a privát fejlécbe, MIELŐTT a Host felülíródik.)*
>
> Az app a `ResolveCustomDomain`-ben ebből a fejlécből olvassa a látogatói hostot — de **csak akkor, ha az edge megbízható**. Enélkül bárki bármelyik tenant domainjét megszemélyesíthetné egy kézzel beállított fejléccel. A fallback origin slugja (`customers`) ezért **reserved subdomain** is: minden custom hostname kérés arra a hostra érkezik, egy tenant nem viheti el.

> **A bizalom bizonyítéka: megosztott titok, nem hálózati pozíció (SLO-140).** Ha az `APP_ORIGINAL_HOST_SECRET` ki van töltve, az edge-nek be kell mutatnia ugyanazt a titkot az `X-Slot4u-Origin-Secret` fejlécben (`hash_equals`, timing-safe), és **ez az egyetlen teszt** — a `REMOTE_ADDR` ilyenkor nem számít. Ha nincs titok konfigurálva, marad a régi viselkedés: `isFromTrustedProxy()` (a Cloudflare-tartományok a `bootstrap/app.php`-ban).
>
> ⚠️ **Miért nem elég a proxy-alapú bizalom:** az éles osztott tárhelyen az Apache `mod_remoteip`/`mod_cloudflare` **már a valódi látogatói IP-re írja át a `REMOTE_ADDR`-t**, mielőtt a PHP látná. Így egyetlen kérés sem *tűnik* Cloudflare mögülinek, `isFromTrustedProxy()` mindig hamis, és minden custom domain 404-et adott (SLO-140, proden mérve). A kézenfekvő „javítás", a `trustProxies('*')`, **rosszabb lenne a hibánál**: azzal az `X-Forwarded-For`-t is elhinnénk bárkitől, azaz kliens-IP-hamisítás lenne az audit logban és a rate limitben. A titok viszont független attól, hogyan bánik a tárhely a `REMOTE_ADDR`-rel.

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
| `slot4u.test` | központi (apex) marketing landing (SLO-50) |
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

A landing „nézd meg működés közben" gombja az `APP_DEMO_TENANT_SLUG` aldoménjére mutat
(`tenancy.demo_slug`, default `demo` — ez az éles `demo.slot4u.hu`). Lokálisan a
`.env.example` `acme`-re állítja, mert `demo` nevű tenantot a seeder nem hoz létre.
Üres érték esetén a landing **nem** rendel demo gombot — dead link helyett inkább semmit.

## Rate limiting (SLO-147)

A publikus (hitelesítetlen) tenant-felület **névvel hivatkozott limitereken** megy
(`AppServiceProvider::definePublicRateLimiters`), nem `throttle:60,1`-szerű beégetett számokon:

| Limiter | Keret | Hol |
|---|---|---|
| `public` | 60/perc | landing, `/book`, `/order`, esemény-jelentkezés, várólista, ajánlatkérés, `/booked/{code}` |
| `checkout` | 20/perc | `/pay/{code}`, sandbox fizetőoldal — minden kísérlet fizetés-sort nyit |
| `seo` | 60/perc | `sitemap.xml`, `og-image.png` (GD-render cache-miss-nél), `robots.txt` |
| `webhook` | 120/perc | `payments/webhook/{provider}` |

⚠️ **A kulcs a tenant HOSTJA + a hívó**, nem csak a hívó. Egy csupasz `throttle:60,1` a hívóra kulcsol,
így multi-tenant appban **egy tenant látogatói egy vödröt osztanak minden más tenantéval**: egy
foglalóoldal elleni burst kizárná egy független tenant ügyfeleit, akik ugyanazon NAT mögül jönnek.
A hoston kulcsolás azért is helyes, mert **nem függ a middleware-sorrendtől**: a verifikált egyedi
domaint a `ResolveCustomDomain` (globális middleware) már a kanonikus aldoménre írta át.

⚠️ **Szándékosan NINCS tenant-szintű plafon** a per-hívó limit fölött. Azzal egy támadó elköltené a
tenant teljes keretét, és a tenant **valódi ügyfeleit** zárná ki — egy méltányossági problémát
cserélnénk rendelkezésre-állásira.

⚠️ **A webhook nagyvonalúan van limitálva, nem szorosan.** Egy visszautasított kézbesítés azt
jelentheti, hogy az app soha nem tud a beérkezett pénzről, és a szolgáltatók eltérően kezelik a 429-et
(nem mind próbálja újra). A limit itt **amplifikáció-védelem, nem hozzáférés-vezérlés** — a hívót az
aláírás hitelesíti (SLO-130).

Teszt rögzíti, hogy **minden hitelesítetlen tenant-route mögött van névvel hivatkozott limiter**
(kivétel nélkül), tehát új publikus végpont nem tud véletlenül limit nélkül bemenni.

## OWASP Top 10 (2021) — állapot (SLO-47 AC)

| # | Kategória | Állapot |
|---|---|---|
| **A01** Broken Access Control | ✅ | `BelongsToTenant` global scope minden tenant-modellen, Policy minden modellre, Form Requestben tenant-hoz kötött `exists` szabályok, saját-scope (`BookingVisibility`/`CustomerVisibility`), **idegen id → 404 nem 403**, és a route-tábla-vezérelt izolációs söprés (SLO-146). |
| **A02** Cryptographic Failures | ✅ | Jelszó bcrypt; tenant számlázó API-kulcs `encrypted:array` casttal (sosem megy Inertia propba); HSTS + `secure`/`httpOnly`/`SameSite` süti; foglalási kód CSPRNG-ből; pénz integer fillérben. ⚠️ **Prod env-függő:** a `SESSION_SECURE_COOKIE` élesen legyen `true` — az induló checklist az SLO-49-é. |
| **A03** Injection | ✅ | Minden lekérdezés Eloquent/query builder kötött paraméterekkel. A `selectRaw`/`orderByRaw` helyek **nem tartalmaznak felhasználói bemenetet** (oszlopnevek literál ternáryból, a két `DB::raw` összefűzés `(int)`-re kényszerített `party_size`). React escape-el; a `dangerouslySetInnerHTML` egyetlen használata a Laravel **paginátor saját** címkéje (`&laquo; Previous`), nem felhasználói tartalom. |
| **A04** Insecure Design | ✅ | Az ütközésvizsgálat DB-szinten is védve (lock / atomi kapacitás-update), soft hold, idempotens webhook (`unique(provider, provider_ref)`), jutalék-ledger invariánsok. A docs/04 edge case-listája tételesen tesztelt. |
| **A05** Security Misconfiguration | 🟡 | Biztonsági headerek + CSP (SLO-145), a sandbox fizetési gateway prodban tiltott, `APP_DEBUG` env-ből. ⚠️ **Prod env-checklist és monitoring hiányzik → SLO-49.** |
| **A06** Vulnerable/Outdated Components | ✅ | `composer audit --locked` + `npm audit --audit-level=high` a CI-ban, minden PR-en **és hetente ütemezve** (SLO-148, l. §Függőség-audit). A küszöb `high`: valódi kapu, nem jelentés. |
| **A07** Identification & Authentication | 🟡 | Fortify; login (5/perc) és regisztráció (5/perc) limitálva; session-regeneráció belépéskor (tesztelve); jelszó min. 12 + breach-ellenőrzés; email-verifikáció. ⚠️ **Nincs 2FA → SLO-149.** |
| **A08** Software & Data Integrity | ✅ | Webhook HMAC aláírás-ellenőrzés írás előtt; számla-PDF **privát** diszken, sosem publikus URL; audit log minden érzékeny műveletre. Külső script alapesetben nincs betöltve (a CSP `script-src 'self'` + nonce ezt ki is kényszeríti). ⚠️ **Egyetlen kivétel (SLO-172):** a `slot4u.hu` marketing-oldalon, **elfogadott analytics-consenttel** a GA4 tag betölt — és a policy is csak ilyenkor nevezi meg a Google originjeit, kérésenként eldöntve. |
| **A09** Logging & Monitoring | 🟡 | `audit_logs`, `notifications_log`, integrációs naplózás megvan. ⚠️ **Riasztás/monitoring nincs → SLO-49.** |
| **A10** SSRF | ✅ | A kimenő hívások mind **fix, konfigurált** végpontokra mennek (Cloudflare API, HIBP, számlázó, fizetési gateway). Az egyetlen felhasználói bemenetet érintő kimenő művelet a domain-verifikáció **DNS TXT lekérdezése** (`DnsResolver`) — nem HTTP-fetch, tehát nem használható belső szolgáltatás elérésére. ⚠️ Ha később bármi **felhasználó által megadott URL-t tölt le** (webhook-kimenet, avatar-import), ezt a sort újra kell nyitni. |

### Függőség-audit (SLO-148)

A CI `audit` jobja két parancsot futtat, **minden PR-en és hetente ütemezve**:

```
composer audit --locked --format=plain
npm audit --audit-level=high
```

⚠️ **Az ütemezés nem díszlet.** Egy sérülékenységet nem a mi naptárunk szerint tesznek közzé:
egy csak PR-re futó audit azt mondja meg, mi volt igaz a merge napján, és utána hallgat,
amíg valaki hozzá nem nyúl a repóhoz. A heti futás az, ami a **már bemergelt** kódról szól.

**A küszöb `high`, és valódi kapu**, nem jelentés. A `low`/`moderate` továbbra is kiíródik,
csak nem buktat — az `--audit-level` a kilépési kódot szabályozza, nem a kimenetet.

⚠️ **A `--locked` szándékos:** a telepített fát auditálni azt jelentené, hogy arról kapunk
jelentést, amit a composer épp feloldott; a **lock fájl** viszont az, ami ténylegesen
kimegy. A kettő pont akkor tér el, amikor számít.

**Miért nem alacsonyabb a küszöb.** Amikor ez a job elkészült, a projekt **17 composer**
(ebből 5 `high`) és **6 npm** (3 `high`, 2 `critical`) sérülékenységgel állt. Mind
javítva lett, **mielőtt** a kapu bekerült — egy ellenőrzés, ami az első napján piros,
nem javítást szül, hanem elnémítást.

**Teendő találat esetén.** Először **frissítés**, nem küszöb-süllyesztés:

* PHP: `composer update <csomag> --with-all-dependencies` (mind a három akkori találat
  **tranzitív** volt — a `composer.json` nem is említi őket).
* npm: `npm audit fix`; `--force` csak tudatosan, mert major verziót emelhet (a
  `@vitejs/plugin-react` például szándékosan `^5.2.0`-n áll, mert a v6 vite 8-at kér).
* Ha egy találat **nem javítható** (nincs kiadva javítás, vagy a javítás töri a stacket),
  az **nem** a küszöb süllyesztésének indoka: rögzítsd itt, indoklással és felülvizsgálati
  dátummal, és hagyd a jobot pirosnak vagy tedd kivételbe **név szerint**.

**Frissítési ritmus.** A lock fájlok frissítése nem naptárhoz kötött, hanem eseményhez: a
heti audit találata, illetve a milestone-záró release előtti átnézés. A `composer.lock`
változása a deployon `composer install`-t vált ki (`docs/16`), tehát egy függőség-frissítés
**mindig kiadás, nem mellékhatás**.

## Tenant-izolációs söprés és a kód-címezhető felület (SLO-146)

**A `TenantIsolationSweepTest` a ROUTE-TÁBLÁT járja be**, nem egy kézzel karbantartott listát: minden
`{tenant}` domainű route-ra, aminek van rekordot címző paramétere, behelyettesíti egy MÁSIK tenant
rekordjának azonosítóját, és **404**-et vár. A route-model binding a controller és a Form Request
**előtt** fut, ezért ehhez semmilyen payload nem kell.

⚠️ **A söprés hibázik, ha egy route-paraméter nincs se leképezve, se dokumentáltan nem-modellként
felsorolva.** Ez szándékos: új végpontnál a lefedettség az alapértelmezett, a kihagyást ki kell
mondani. A nem-modell paraméterek indoklással: `{tenant}` (maga az aldomén), `{provider}` (fizetési
szolgáltató neve, a webhook aláírással hitelesít), `{key}` (NotificationType, minden tenant közös
szótára), `{role}` (role NÉV, a tenant spatie-teamjén belül feloldva) — az utóbbi kettőre külön,
célzott teszt van, mert ott nem az id, hanem a név a támadási felület.

⚠️ **Amit a söprés első futása kihozott:** a `PUT /settings/users/{user}/rbac` **nem 404-gyel**
válaszolt idegen id-re, hanem azzal, amit a validáció mondott — mert a `User`-nek (jó okkal) nincs
tenant global scope-ja, így a tagsági ellenőrzés a controllerben, tehát a Form Request UTÁN futott.
Adatszivárgás nem volt (üres payloaddal a saját és az idegen id ugyanazt adta), de a szabály nem tartott.
Javítás: **`App\Models\TenantUser`** (ugyanaz a minta, mint a `Customer`) — a binding csak az aktuális
tenant staff-tagjára old fel, minden más 404 **még a validáció előtt**.

**Kód-címezhető publikus végpontok** (`/booked/{code}`, `/booked/{code}/ics`, `/pay/{code}`,
`/payments/sandbox/{provider_ref}`): itt **a kód maga a hitelesítő**, mint egy kitalálhatatlan link a
visszaigazoló emailben. Ez két feltételen áll, és **mindkettőt teszt rögzíti**: (1) a foglalási kód
**8 karakter egy 31 elemű ábécéből ≈ 2^39,6 lehetőség**, `random_int` CSPRNG-vel; (2) minden ilyen
route **throttle alatt van** (60/perc, a fizetési ág 20/perc) — a `PublicCodeAccessTest` fel is sorolja
a route-táblából, hogy nincs köztük throttle nélküli. A kettő együtt teszi a kimerítő keresést
értelmetlenné; a kód rövidítése vagy a throttle tágítása elrontaná az aritmetikát, ezért mindkettő
teszttel van kikötve. **Ismeretlen kód és idegen tenant kódja ugyanazt a 404-et adja** — különben a
visszaigazoló oldal orákulum lenne arra, mely kódok léteznek.

## Biztonsági válasz-headerek (SLO-145)

A `SecurityHeaders` middleware **globálisan** (nem csak a `web` láncban) fut, és a lánc **elejére**
van fűzve: a CSP nonce-nak már azelőtt léteznie kell, hogy bármi script-taget renderelne. Minden
válasz visz `X-Content-Type-Options: nosniff`-et (egy JSON- vagy PDF-választ is meg kell védeni a
tartalom-szimatolástól), `X-Frame-Options`-t, `Referrer-Policy`-t és `Permissions-Policy`-t; a
konkrét értékek a `config/security.php`-ban vannak, mert egy beágyazható foglaló-widget később
jogosan tágíthatja őket.

**HSTS csak HTTPS-en megy ki.** Egy sima http-s dev hostról kiküldött `max-age` egy évre elérhetetlenné
tenné azt a hostot — ez önokozta üzemzavar, nem védelem.

**A CSP nonce-alapú, `unsafe-inline` nélkül.** A gyökér Blade egyetlen inline scriptet tartalmaz (a
villanásmentes téma-váltó), és a Laravel Vite helper ugyanazt a nonce-ot bélyegzi a saját tagjeire —
így a `script-src` szigorú maradhat. ⚠️ Az Inertia a propokat `<script type="application/json">`
**adatblokkba** teszi, ami nem futtatható, ezért a `script-src` rá nem vonatkozik (nonce sem kell neki).
A `style-src` **tudatos kivétel** (`unsafe-inline`): a Radix és a toast-könyvtár futásidőben szúr be
`<style>` elemeket, és egy stílus-injekció nagyságrendekkel kisebb nyeremény, mint a script-futtatás.
A policy-t a `App\Support\ContentSecurityPolicy` építi — külön osztály, hogy a **dev és a prod ág is
tesztelhető** legyen Vite dev szerver nélkül. ⚠️ **A mérés originjei (SLO-172) nem env-ből jönnek,
hanem kérésenként** (`$analytics` konstruktor-paraméter): ugyanabból az objektumból, amelyik a root
Blade-nek megmondta, hogy kimenjen-e a tag. A `SECURITY_CSP_SCRIPT_SRC` tágítása ehelyett a
googletagmanager.com-ot **minden oldalon örökre** futtathatóvá tenné — az admin panelen és a foglalási
folyamaton is —, azért, hogy egyetlen marketing-oldalon fusson egy script. Aki nem járult hozzá a
méréshez, annak a policy is visszaszűkül. A dev ág (`'unsafe-eval'` + a dev szerver origin, a
React Refresh miatt) **a `hot` állapotra van kötve, nem környezet-névre**, tehát buildelt bundle
mellett strukturálisan nem tud érvényre jutni. A websocket origin (`connect-src`) az **AKTÍV** broadcast connectionből jön
(`broadcasting.default`), különben az élő foglalás-feed minden oldalon elakadna. ⚠️ **Ezt driver-névre
kötni hiba (SLO-150):** dev alatt Reverb fut, **prodban hosted Pusher** — a beégetett `reverb` név
prodban üres `connect-src`-t adott volna, tehát pont ott blokkolta volna a realtime-ot, ahol számít.
⚠️ **Pushernél a configolt `host` az `api-{cluster}.pusher.com`, az a SZERVER-oldali REST végpont** — a
böngésző a `ws-{cluster}`-re csatlakozik, tehát a kliens hosztját le kell vezetni, nem átmásolni.

**Jelszó-szabály:** `Password::defaults()` = **min. 12 karakter + breach-ellenőrzés** (haveibeenpwned
k-anonimitás). Korábban a kód `Password::default()`-ot használt anélkül, hogy a defaultok valaha
definiálva lettek volna — vagyis a szabály a Laravel csupasz 8 karaktere volt, olyan fiókokra, amik
egy tenant teljes ügyfélkörét kezelik. A breach-ellenőrzés hálózati hívás, ezért a teszt-suite egy
fake verifiert köt be (`Tests\Fixtures\FakeUncompromisedVerifier`) — a suite sosem függ a hálózattól.

## Eseménybekötés (SLO-174)

**A listenerek EXPLICIT módon vannak bekötve**, az `AppServiceProvider::boot()`-ban, és a
**felfedezés ki van kapcsolva**: `bootstrap/app.php` → `->withEvents(discover: false)`.

⚠️ **Miért kellett kikapcsolni.** Az `Application::configure()` magától meghívja a
`withEvents()`-et `$discover = true`-val, ami végigjárja az `app/Listeners` mappát, és minden
osztályt bejegyez, amelynek a `handle()`-je eseményt nevez meg — **az explicit
`Event::listen()` hívások MELLÉ**. Emiatt minden foglalási esemény minden listenere **kétszer
futott le**: a visszaigazoló email, a jutalék-főkönyv, a broadcast, mind.

**Semmi nem tört el látványosan**, mert a listenerek véletlenül idempotensek (a notifier
dedupe-kulcsa, a főkönyv upsertje). Ez szerencse volt, nem tervezés, és az első nem-idempotens
listenernél elfogyott volna — pontosan ez lett volna a Meta-konverzió (SLO-173), ha nem atomi
claim mintával épül.

**Az explicit bekötés nyert a felfedezéssel szemben**, mert az `AppServiceProvider` az egyetlen
hely, ahol a bekötés olvasható, kereshető és kommentelhető. A felfedezés ma semmit nem tett
hozzá: minden felderített listenernek volt explicit párja is (empirikusan ellenőrizve a
váltás előtt).

**Új listenernél tehát:** az `AppServiceProvider::boot()`-ba KELL egy `Event::listen(...)` sor
— a mappába bemásolás önmagában már nem köti be. A `tests/Feature/EventWiringTest.php` őrzi
mindkét irányt: nincs kétszer bejegyzett listener, és a meglévők nem tűntek el.

## Vizuális arculat (SLO-170)

| Asset | Hol a forrás | Mire való |
|---|---|---|
| `resources/images/brand-tile.svg` | vektor, commitolva | a fejléc/lábléc lockup **és** minden ikon forrása |
| `public/img/favicon-32.png`, `apple-touch-icon.png`, `icon-192/512.png` | generált, commitolva | böngésző- és launcher-ikonok |
| `public/img/og-image.png` (1200×630) | generált, commitolva | link-előnézeti kártya |

**Újragenerálás:** `php artisan brand:icons <négyzetes-png-export>`.
⚠️ A parancs **raszter útvonalat vesz át**, nem a commitolt SVG-t olvassa: ezen a hoston
semmi nem tud SVG-t raszterizálni (nincs ImageMagick, nincs librsvg). A vektor marad az
igazság forrása; újragenerálni annyi, hogy egyszer exportálod PNG-be. Ezt kimondani jobb, mint
egy parancs, ami csendben egy elavult rasztert olvas, és úgy tesz, mintha a vektort olvasná.

⚠️ **A fejlécben a CSEMPE van, nem a lógó lajhár** — és ez az elsőre rossz döntés javítása.
Egymás mellé renderelve a lógó illusztráció **~26 px-nél beige folttá esik szét** egy teal
csíkkal, a csempe viszont 20 px-en is arcként olvasható. Az egyik illusztráció, a másik ikon;
egy fejléc ikont kér. Ez oldja meg a világos téma problémáját is: a szabadon álló jel krém
színe fehéren majdnem láthatatlan, a csempe viszont hozza a saját sötét alapját.

⚠️ **A platform akcentusa (teal, `#22DECB`) CSAK a marketing-felületre vonatkozik.** A
`MarketingLayout` felülírja a `--primary` tokent — pontosan azzal a mechanizmussal, amivel a
tenant publikus shellje a saját márkaszínét állítja, tehát a kettő nem tud egymáshoz érni. A
`TenantBranding::DEFAULT_PRIMARY_COLOR` **szándékosan indigó marad**: a tenant foglalóoldala
az Ő márkája, nem a miénk, és minden színt nem választó tenantot átfesteni annyi lenne, mint a
platform kiszolgálja magát mások kirakatából (ugyanaz a határvonal, mint `docs/19` §2).

## N+1 védelem (SLO-155)

**Az `AppServiceProvider::boot()` bekapcsolja a `Model::preventLazyLoading()`-ot mindenhol,
KIVÉVE prodban.** A Definition of Done M2 óta megköveteli az „N+1 ellenőrizve a listázó
végpontokon" pontot — és ellenőrizve is volt: kézzel, aki épp eszébe jutott. Ettől a következő
elfelejtett eager load a **teszten bukik el**, nem egy oldal formájában, ami valamikor lassabb
lett, és senki nem tudja megmondani, mikor.

⚠️ **Prodban szándékosan nincs bekapcsolva.** Egy elfelejtett reláció ott lassú oldal; ugyanaz
kivételként **500-as hiba egy foglalási űrlapon**. A védelem oda való, ahol ember látja — a
fejlesztői gépre és a CI-ba —, nem a vendég elé.

⚠️ **A Laravel csak 2+ soros lekérdezésnél fegyverzi fel a modelt** (`Builder::hydrate`,
`count($items) > 1`). Ez nem hiányosság: egy darab lusta betöltés nem N+1, hanem egy plusz
query. A védelem pont azt az **alakzatot** fogja meg, ami számít — végigiterálni egy
kollekción, és minden körben visszamenni az adatbázishoz.

**Ha egy service-nek olyan reláció kell, amit a hívó nem töltött be:** `loadMissing()`, ott,
ahol a relációt olvassa (l. `CustomerNotifier::sendToContact`). A védelem a **véletlent**
tiltja, nem a késői betöltést.

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
- Monitoring: **Sentry** (backend + böngésző, DSN nélkül teljesen no-op), `monitor:health` watchdog a cron-vezérelt queue-ra és schedulerre (`heartbeats` tábla), külső **dead man's switch** a teljes app halálára, és `/up` uptime-végpont DB-próbával. PII-szabályok, riasztási runbook: **`docs/17-monitoring-es-riasztas.md`**.
- Deploy: GitHub Actions, `v*` verziótagre, **jóváhagyási kapuval** (`production` environment) — a tag javaslatot tesz, az ember dönt. A logika verziókövetett shell scriptekben (`deploy/deploy.sh`, `deploy/rollback.sh`, `deploy/smoke.sh`), hogy a szerveren kézzel is futtatható legyen és a rollback ugyanazt a kódutat járja. Folyamat, beállítandó kulcsok és rollback-eljárás: **`docs/16-deploy-pipeline.md`**. Staging környezet és valódi zero-downtime (release-könyvtárak): SLO-156.

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
