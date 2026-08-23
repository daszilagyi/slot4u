# 17. Monitoring és riasztás — mi szól, kinek, és mit kell tenni

> Állapot: 2026-08-09 (SLO-153). Commitolható: nevek és eljárások, titok nélkül.
> A DSN-ek, tokenek és a heartbeat-URL a prod `.env`-ben és a GitHub secretek közt élnek.

## 1. Miért

Eddig **semmilyen külső hibakövetés nem volt**: a prod hibák a `storage/logs`-ba mentek,
ahova senki nem néz be. Egy 500-as a publikus foglalófelületen észrevétlenül elvihette egy
tenant teljes heti foglalását.

A második, csendesebb kockázat az üzemeltetési profilból jön (SLO-125): **nincs Horizon és
nincs daemon** — a queue percenkénti cronból fut, `flock`-kal. Ha az a cron elhal, az app
tovább szolgálja ki az oldalakat, miközben **egyetlen visszaigazoló email sem megy ki**.
Ez a fajta hiba nem dob kivételt; csak csend van.

## 2. A három réteg

| Réteg | Mit lát | Mi a jele | Mikor néma |
|---|---|---|---|
| **Sentry** | kivételek (PHP + böngésző), és a `monitor:health` részletes riasztásai | esemény a Sentryben | ha a folyamat el sem indul |
| **Dead man's switch** | hogy az app **egyáltalán** él és egészséges | a ping **hiánya** | soha — ez a lényege |
| **Uptime check** (`/up`) | kívülről elérhető-e az oldal, és él-e a DB | HTTP 500 / időtúllépés | ha az edge cache-el egy régi választ |

A három szándékosan fedi egymást: mindegyik ott vak, ahol a másik lát.

## 3. Sentry

* Backend: `sentry/sentry-laravel`, a `bootstrap/app.php` `Integration::handles()` hívásával.
  Laravel `dontReport` listája **előbb fut**, tehát 404, validációs hiba és auth-redirect nem
  incidens.
* Frontend: `@sentry/react`, **dinamikus importtal** (`resources/js/lib/monitoring.ts`) —
  a ~30 kB-os SDK külön chunkba kerül, és DSN nélkül le sem töltődik.
* **DSN nélkül minden néma.** A transport DSN híján eldobja az eseményt, mielőtt socketet
  nyitna → dev és CI egyetlen kimenő hívást sem tesz (ugyanaz a tartás, mint a Null
  custom-hostname provisionernél, SLO-135).

### 3.1 Mit NEM kap meg a Sentry

Az `App\Support\Monitoring\SentryScrubber` **minden** eseményt átenged magán:

| Adat | Sorsa |
|---|---|
| request body (`guest_name`, `guest_email`, `guest_phone`, jegyzetek) | **egyáltalán nem gyűlik** (`max_request_body_size: none`) |
| query string, cookie-k, headerek, `$_SERVER` | eldobva — a `url` és a `method` marad |
| user | **csak az `id`** — a név, email, IP újraépítéskor elvész |
| email-cím / telefonszám prózában (kivétel-üzenet, log, breadcrumb) | `[redacted-email]` / `[redacted-phone]` |
| SQL binding | soha nem gyűlik |
| tenant | **marad** (`tenant`, `tenant_id` tag) — ez a kért kontextus |

⚠️ **A határ, amit érdemes ismerni:** a redaktálás az email-t és a telefonszámot ismeri fel.
**Neveket nem** — azokra nincs megbízható minta. Ezért a védelem elsődlegesen az, hogy a
strukturált adat (request body, user mezők) **el sem indul**; a regex csak a prózára szóló
második vonal.

## 4. Watchdog: `php artisan monitor:health`

Ötpercenként fut a schedulerből (`--beat` kapcsolóval). Amit ellenőriz:

| Check | Bukik, ha | Miért fáj |
|---|---|---|
| `queue` | a worker `MONITORING_QUEUE_STALE_MINUTES` (15) percnél régebben futott, **vagy soha nem futott** | nem megy ki visszaigazoló email, emlékeztető, jutalékszámla |
| `failed_jobs` | legalább 1 sor a `failed_jobs`-ban | egy failed job = egy ügyfél, aki nem kapott visszaigazolást |
| `scheduler` | a scheduler 15 percnél régebben futott | lejáró trial, várólista, dunning, számlazárás mind áll |
| `backup` | az utolsó **sikeres** offsite mentés `BACKUP_STALE_AFTER_HOURS`-nál (36) régebbi, **vagy soha nem volt** | egy mentés, ami csendben abbahagyta a futást, a szükség napján derül ki (SLO-154, docs/18) |

A `backup` check **csak ott fut, ahol a mentés be van állítva** — dev és CI nem kap riasztást
arról, hogy nincs offsite bucketje. Az életjelet a `backup:run` a **feltöltés után** írja,
tehát egy hetek óta visszautasított feltöltés nem tud egészségesnek látszani.

A queue-életjelet **maga a worker** írja: minden `Looping` eseménynél (nem feldolgozott
jobnál — a cron-worker üres sorral azonnal kilép, így egy csendes éjszaka is „élő"). Az
életjelek a `heartbeats` táblában vannak, **nem a cache-ben**: egy cache-ürítés pontosan úgy
nézne ki, mint egy halott worker, és a hamis riasztás gyorsan lenémítja a riasztást.

### 4.1 A dead man's switch

Ha **minden** check átment, a parancs meghív egy külső heartbeat-URL-t
(`MONITORING_HEARTBEAT_URL` — healthchecks.io, Better Stack, bármi). A külső szolgáltatás a
ping **elmaradására** riaszt.

Ez a rész éli túl az app halálát: a Sentry csak addig tud szólni, amíg fut a folyamat, egy
leállt cron pedig végképp nem jelent semmit. Ezért **a ping a parancs végén van, nem külön
cronban** — így a „minden rendben" üzenetet nem tudja elküldeni egy olyan host, amin épp
nincs minden rendben.

⚠️ Következmény: **hiba esetén szándékosan NEM pingelünk.** Ez nem elfelejtett hívás.

## 5. Uptime check

Az UptimeRobot / Better Stack a **`https://slot4u.hu/up`** címet verje (1 perc).

* Laravel saját health route-ja önmagában csak azt bizonyítja, hogy a PHP elindult — ezért a
  `VerifyDatabaseIsReachable` listener egy `select 1`-et is lefuttat. **Elérhetetlen DB → 500.**
* A válasz **semmit nem árul el**: se verziót, se konfigot, se hibaüzenetet. A részletes
  állapot a tokennel védett `/_deploy/health` mögött van (SLO-152).
* ⚠️ A böngésző-oldali hibajelentéshez a **CSP `connect-src`-be be kell kerülnie a Sentry
  ingest hostjának** — ezt az app a DSN-ből vezeti le. Ha a DSN csak a bundle-ben van, de a
  szerver `.env`-jében nincs, a böngésző **minden hibajelentést blokkol**, és pont az nem
  derül ki soha, hogy a hibajelentés nem működik (ez az SLO-150 tanulsága).

## 6. Runbook — ki kap riasztást és mit tegyen

**Ma egyetlen ügyeletes van: Daniel.** (Amíg nincs második ember, a „kit értesítsünk"
kérdésnek nincs más őszinte válasza.) Csatornák: Sentry email/alert szabály + a heartbeat-
szolgáltatás értesítése.

| Riasztás | Első lépés | Ha nem oldódik meg |
|---|---|---|
| **Sentry: új kivétel a publikus foglalófelületen** | `tenant` tag → melyik tenant, melyik URL. Reprodukálás staging nélkül: ugyanaz az URL a saját tenanton. | Ha a legutóbbi deploy után jött: **rollback** (docs/16 §5). |
| **Sentry: „Health check failed: queue …"** | SSH: `crontab -l` — megvan-e a queue-sor? `tail ~/logs/queue.log`. Kézzel: `php artisan queue:work --stop-when-empty`. | Ha a cron fut, de nem halad: `php artisan queue:failed`, és lásd a következő sort. |
| **Sentry: „… failed_jobs: N failed job(s)"** | `php artisan queue:failed` → mi bukott. Ha átmeneti (SMTP timeout): `php artisan queue:retry all`. | Ha kódhiba: javító PR, utána retry. A `failed_jobs` táblát **ne ürítsd** vizsgálat nélkül — minden sor egy elmaradt ügyfél-értesítés. |
| **Sentry: „… scheduler …"** | `crontab -l` → megvan-e a `schedule:run` sor? `tail ~/logs/scheduler.log`. | A cron újrafelvétele a docs/13 §5 szerint. |
| **Heartbeat kimaradt (dead man's switch)** | Először: **él-e egyáltalán az oldal?** (`/up`). Ha igen, akkor a scheduler áll → a fenti sor. Ha nem, akkor host-szintű baj. | Tárhely.Eu support; közben statikus tájékoztatás. |
| **Sentry: „Backup failed …"** | `php artisan backup:list` — mikori a legutolsó jó mentés? A hibaüzenet megnevezi a bukott lépést (dump / feltöltés / titkosítás). | Kézi futtatás: `php artisan backup:run`. Ha a dump bukik: `mysqldump` elérhető-e, van-e hely. Restore-eljárás: **docs/18 §4**. |
| **Sentry: „… backup: the last successful backup was N hours ago"** | Fut-e egyáltalán a scheduler? (`scheduler` check). Ha igen, a `backup:run` bukik csendben → nézd a logot. | docs/18 §3. Amíg nincs friss mentés, **ne indíts sémát érintő deployt**. |
| **Uptime: `/up` 500** | Szinte biztosan a DB. cPanel → MySQL él-e; `php artisan db:show`. | Ha a DB él, de az app nem: nézd meg, nem maradt-e karbantartási módban egy félbeszakadt deploy (`php artisan up`). |
| **Uptime: időtúllépés / 5xx az edge-től** | Cloudflare status + origin közvetlen elérése. | Tárhely.Eu support. |

**Kézi ellenőrzés bármikor:**

```bash
php artisan monitor:health      # --beat NÉLKÜL: nem frissíti a scheduler életjelét
```

A `--beat` szándékosan hiányzik a kézi futtatásból: különben a vizsgálat épp azt a jelet
frissítené, amit értékel, és egy halott scheduler élőnek látszana annak, aki nyomozza.

## 7. Beállítandó kulcsok

| Hol | Kulcs | Megjegyzés |
|---|---|---|
| prod `.env` | `SENTRY_LARAVEL_DSN` | backend projekt |
| prod `.env` | `VITE_SENTRY_DSN` | frontend projekt — **a CSP miatt** kell a szerverre is |
| prod `.env` | `MONITORING_HEARTBEAT_URL` | dead man's switch; üresen nincs kimenő hívás |
| prod `.env` | `MONITORING_QUEUE_STALE_MINUTES`, `MONITORING_FAILED_JOBS_THRESHOLD` | opcionális hangolás |
| prod `.env` | `AWS_*`, `BACKUP_PASSPHRASE` | offsite mentés (SLO-154) — a teljes lista **docs/18 §2** |
| GitHub variables | `VITE_SENTRY_DSN`, `VITE_SENTRY_ENVIRONMENT` | build-time égnek a bundle-be (docs/16 §3.3) |

Semmi nem kötelező: minden kulcs nélkül az app pontosan úgy működik, mint eddig, csak nem
szól, ha baj van.

## 8. E-mail-kézbesíthetőség (SLO-169)

Ez azért van a monitoring-doksiban, és nem a deploy-listában: a rossz kézbesíthetőség
**néma hiba**. Semmi nem dob kivételt, a `notifications_log` `sent`-et ír, a queue üres — az
ügyfél viszont nem kapja meg a visszaigazolást, és azt hiszi, a foglalás nem sikerült. Nincs
az a riasztás, ami ezt észrevenné; **csak a rendszeres újramérés**.

### 8.1 A küldési út

| Mi | Érték | Miért így |
|---|---|---|
| Feladó | `From: slot4u <no-reply@slot4u.hu>` | **Minden** tenant erről a címről küld. Tenant saját domainjéről küldeni tilos: azon nincs se SPF-felhatalmazásunk, se DKIM-kulcsunk. ⚠️ A docs/11 §8 a tenant nevét kérné display name-ként és a tenant címét `Reply-To`-ban — **ez ma nincs bekötve** (a 2026-08-23-i mérés fejlécei), l. **SLO-171**. |
| SMTP | `tbfiftyseven.tarhely.eu:465` (`MAIL_SCHEME=smtps`) | ⚠️ A prod `.env`-ben **a gép neve** áll, nem `mail.slot4u.hu` — ugyanaz a `178.238.222.57`, ezért az SPF (`+a +mx`, IP-alapú) így is átmegy. A HELO viszont `tbfiftyseven.tarhely.eu`, aminek nincs saját SPF-je (`SPF_HELO_NONE`, 0.0 pont) — a hoster gépneve, nem a miénk, nem javítható és nem is számít. |
| MX-cél | `mail.slot4u.hu` → `178.238.222.57` | **Nem Cloudflare-proxyzott** — az MX-célnak az origin IP-t kell adnia, különben a bejövő levél a proxyn akad el. |

### 8.2 A négy rekord — DNS-igazolás (2026-08-23)

```
$ dig +short slot4u.hu TXT @1.1.1.1
"v=spf1 +a +mx +exists:%{i}.spfcheck.eu ~all"

$ dig +short default._domainkey.slot4u.hu TXT @1.1.1.1
"v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0nqGbRqilp+Ix..." (2048 bit)

$ dig +short _dmarc.slot4u.hu TXT @1.1.1.1
"v=DMARC1; p=none; sp=none;"

$ dig +short slot4u.hu MX @1.1.1.1
0 mail.slot4u.hu.
```

* **SPF ✅** — a `+a +mx` pont azt a gépet hatalmazza fel, amelyik küld (`mail.slot4u.hu` =
  a web-origin IP-je). A `~all` softfail indulásra helyes.
* **DKIM ✅** — a `default` selectoron 2048 bites RSA kulcs. ⚠️ Ez **változás**: a docs/11 §8
  2026-07-19-i mérése még `DKIM ❌`-et írt; a cPanel „Email Deliverability → Repair" azóta
  felvette. A régi mérésre hivatkozó jegyzet elavult.
* **DMARC ⚠️** — létezik és szintaktikailag érvényes, de **`rua=` nélkül**. Így a `p=none`
  semmit nem ér: `p=none` = „ne csinálj semmit", `rua` nélkül pedig jelentés sem érkezik —
  azaz sem védelem, sem adat. **Teendő:** `rua=mailto:dmarc@slot4u.hu` felvétele a
  Cloudflare DNS-ben; szigorítás (`p=quarantine`) csak azután, hogy pár hét jelentése
  megmutatta, mi küld még a domainről.

### 8.3 A mérés — 2026-08-23, **9.5/10**

Az éles rendszerből kiküldött valódi foglalás-visszaigazoló mail-tester eredménye:
**9.5/10 — „Wow! Perfect, you can send."** (SpamAssassin `-0.2/5.0`).

```
Authentication-Results: dmarc=pass (p=none dis=none) header.from=slot4u.hu
Authentication-Results: dkim=pass (2048-bit key) header.d=slot4u.hu header.s=default
Received-SPF: Pass (mailfrom) client-ip=178.238.222.57; helo=tbfiftyseven.tarhely.eu
X-Spam-Test-Scores: DKIM_SIGNED=0.1, DKIM_VALID=-0.1, DKIM_VALID_AU=-0.1,
                    DKIM_VALID_EF=-0.1, SPF_PASS=-0.001, HTML_MESSAGE=0.001
```

A `DKIM_VALID_AU` a lényeg: az aláírás a **szerző domainjével** egyezik, nem csak
valamilyen kulccsal van aláírva — ez az, amit a Gmail és a DMARC néz.

**A hiányzó 0.5 pont a `List-Unsubscribe` fejléc**, és ez így helyes: ez tranzakciós
levél, a saját foglalásod visszaigazolásáról nem lehet „leiratkozni". A Gmail/Yahoo
tömeges-küldő szabálya a marketing levelekre vonatkozik, nem erre. **Ne „javítsd" —
9.5 ennél a levéltípusnál a plafon.**

### 8.4 Újramérés — hogyan

Éles gépen, egyetlen paranccsal, **prod-adat írása nélkül**:

```bash
# 1. Kérj egy eldobható címet a https://www.mail-tester.com oldalon.
# 2. Éles gépen (a CLI default PHP-ja 8.2, ezért a teljes útvonal kell):
cd ~/slot4u && /opt/cpanel/ea-php84/root/usr/bin/php \
  artisan mail:deliverability-test <cím>@srv1.mail-tester.com
# 3. Töltsd újra a mail-tester oldalt → pontszám. Cél: ≥ 9/10.
```

A parancs a **valódi** foglalás-visszaigazolót küldi (ugyanaz a mailer, feladó, HTML és
tenant-sablon), de **nem menteni** semmit: a `Booking` mentetlen példány, és a
`notifications_log` claim (ami a `Notifier`-ben keletkezne) kimarad. Azért `sendNow`, mert a
queue-t percenkénti cron üríti — egy sorba tett kézbesítési teszt eredménye egy perccel
később egy worker-logban jelenne meg, nem a parancs kilépési kódjában.

A DNS-oldal ellenőrzése `dig` nélküli gépről (DoH):

```bash
curl -s -H 'accept: application/dns-json' \
  'https://cloudflare-dns.com/dns-query?name=_dmarc.slot4u.hu&type=TXT'
```

### 8.5 Újramérés — mikor kötelező

Bármelyik teljesül → új mérés, és az eredmény ide:

* **A küldő infrastruktúra változik.** Tranzakciós szolgáltatóra állunk (Resend / Postmark /
  SES — a docs/11 §8 szerint a 3000/óra kvóta miatt ez idő kérdése): **új** SPF-include és
  **új** DKIM-selector kell, a mostani rekordok nem hordozzák át magukat.
* **Változik a feladó cím vagy a domain** (`MAIL_FROM_ADDRESS`, új küldő aldomain).
* **Szerverköltözés / új origin IP** — az SPF `+a +mx` az IP-re mutat.
* **DMARC-szigorítás** (`p=none` → `quarantine`/`reject`): előtte mérés, utána mérés.
* **Új levéltípus, ami tömegesen megy ki** (emlékeztető-hullám, marketing) — más a tartalom,
  más a spam-pontszám.

### 8.6 Analytics — mi mér a launchkor

**Nincs GA4 és nincs Meta Pixel.** A docs/08 ezeket Phase 2-be sorolja, és a launchhoz nem
kell: a „működik-e" kérdésre a Sentry + a watchdog válaszol (§3–§4), a „hányan foglalnak"
kérdésre pedig a saját adatbázis — a foglalások és a jutalék-periódusok ott vannak, tenant
szerint bontva. Egy külső mérő ma csak annyit adna hozzá, hogy a látogató forrását is látnánk
— cserébe consent-menedzsmentet és adatfeldolgozói kitettséget hozna. A consent-kapu (SLO-165)
**készen áll**, tehát ha a mérés később kell, a bekötése nem blokkolt.

## 9. Ami tudatosan kimaradt

* **Performance tracing és session replay.** A Sentry drága fele — kvótában és abban is,
  amit gyűjt (span-ek URL-lel és SQL-lel, replay az ügyfél gépeléséről). A kérdés itt az,
  hogy *elromlott-e valami*, nem az, hogy lassú-e. A teljesítmény az **SLO-155**.
* **Disk/DB metrikák, log retention.** SLO-155. A mentés és a visszaállítás már megvan:
  **docs/18**.
* **Több ügyeletes, eszkalációs lánc.** Egy fejlesztő van; a színlelt rotáció rosszabb, mint
  a nyílt beismerés.
