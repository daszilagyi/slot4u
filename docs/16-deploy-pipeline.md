# 16. Deploy pipeline — verziótagtől a prodig

> Állapot: 2026-08-09 (SLO-152). Ez a fájl **commitolható**: nem tartalmaz titkot, csak
> a folyamatot és a beállítandó kulcsok NEVÉT. A prod hozzáférések és a hosting-specifikus
> jegyzetek továbbra is a gitignore-olt `docs/11`–`docs/14`-ben élnek.
>
> Ez a pipeline **kizárólag a prodot** kezeli. A staging környezet és a valódi
> zero-downtime (release-könyvtárak + `current` symlink) tudatosan kimaradt → **SLO-156**.

## 1. A folyamat egy mondatban

**A verziótag javaslatot tesz, az emberi jóváhagyás dönt.** A `v*` tag elindítja a
`Deploy` workflow-t, az assetek felépülnek, majd a job megáll a `production`
GitHub Environment jóváhagyási kapujánál — addig **egyetlen bájt sem megy a szerverre**.
Jóváhagyás után: asset-feltöltés → karbantartási mód → checkout + migráció + cache →
karbantartási mód vége → **füstteszt**, ami hangosan buktat.

```
git tag v0.8.0-M8 && git push origin v0.8.0-M8
      │
      ▼
[build]  npm ci → npm run build (VITE_* build-time!) → manifest-pillanatkép → artifact
      │
      ▼
[deploy] ⏸ VÁR a production environment jóváhagyására
      │
      ├─ rsync public/build → szerver          (a karbantartási ablak ELŐTT)
      ├─ ssh … bash -s < deploy/deploy.sh <tag>
      │        artisan down → git checkout → composer (ha kell) → migrate
      │        → .release → cache-ek → queue:restart → artisan up
      └─ deploy/smoke.sh  → él? a VÁRT verzió szolgál ki? van-e lefuttatatlan migráció?
```

## 2. Miért így

| Döntés | Indok |
|---|---|
| **Jóváhagyási kapu a tagen** | A tag a projektben *release-jelölés* (CLAUDE.md), nem „nyomd ki most". Az `environment: production` required reviewerrel a tag önmagában semmit nem tol ki. |
| **A logika shell scriptben, nem inline YAML-ben** | Így review-zható, a szerveren kézzel is futtatható (`bash deploy/deploy.sh v0.8.0-M8`), és a rollback ugyanazt a kódutat járja, mint a deploy. |
| **A script ssh-n *bepipe*-olva fut** (`bash -s < deploy/deploy.sh`) | A futó deploy-logika mindig a deployolt taghez tartozó változat — nem az, ami történetesen a szerveren hever. |
| **Asset build a CI-ban** | A szerveren nincs Node (docs/13). Csak a `public/build` megy fel. |
| **A feltöltés a `artisan down` ELŐTT** | A feltöltés a leglassabb lépés; a hash-elt új fájlok nyugodtan megjelenhetnek a régiek MELLETT, amíg az oldal él. |
| **`composer install` kihagyása, ha a `composer.lock` nem változott** | Ez a leghosszabb lépés a karbantartási ablakon belül. A rutin deploy így pár másodperc. |
| **Az `--delete` NINCS az rsync-en** | Az egy másodperce betöltött oldal még az előző build hash-elt fájljait kéri, és a rollbacknek is kellenek. Helyette 30 napnál régebbi fájlok takarítása a deploy VÉGÉN. |
| **Release-enkénti manifest-pillanatkép** | A `manifest.json` egyetlen fájl, amit a következő release felülír. Nélküle a rollback régi PHP-t futtatna az új bundle-lel — pont azt az állapotot, ami elől menekül. |
| **Hiba esetén az oldal karbantartási módban MARAD** | A félig migrált, kérést kiszolgáló app rosszabb, mint egy 503. A script kiírja a pontos visszaút-parancsot. |
| **A refet tag → `origin/<ref>` → sha sorrendben oldjuk fel, és a workflow átadja a buildelt commitot** | A szerver **lokális** branchei sosem mozdulnak (a `fetch` az `origin/main`-t frissíti, a `main`-t nem) — enélkül a `ref=main` deploy a klónozáskori commitot tolta ki, és **sikert jelentett** (SLO-158). Eltérő commitnál a script most **elutasítja** a deployt. |

## 3. Egyszeri beállítás

### 3.1 GitHub Environment

`Settings → Environments → New environment: production`

* **Required reviewers:** Daniel (ez maga a kapu — enélkül a tag magától deployolna).
* Ide (environment secrets) kerül: `DEPLOY_SSH_KEY`, `DEPLOY_SSH_KNOWN_HOSTS`, `DEPLOY_HEALTH_TOKEN`.

### 3.2 Repository **secrets** (environment-scope: `production`)

| Név | Tartalom |
|---|---|
| `DEPLOY_SSH_KEY` | A deploy user privát kulcsa (teljes PEM tartalom). A publikus párja a szerver `~/.ssh/authorized_keys`-ében. |
| `DEPLOY_SSH_KNOWN_HOSTS` | `ssh-keyscan -p <port> <host>` kimenete. **Kötelező**: deploy közbeni `ssh-keyscan` azt jelentené, hogy bárkinek elhisszük, aki felveszi a telefont. |
| `DEPLOY_HEALTH_TOKEN` | `openssl rand -hex 32`. **Ugyanez kell a szerver `.env`-jébe is** (`DEPLOY_HEALTH_TOKEN=…`), különben a füstteszt verzió-ellenőrzése 404-et kap. |

### 3.3 Repository **variables** (nem titkosak)

| Név | Példa | Megjegyzés |
|---|---|---|
| `DEPLOY_HOST` | *(az origin IP-je)* | ⚠️ **NEM `slot4u.hu`**: a zóna Cloudflare mögött van, a névhez tartozó IP a CF-é, ami nem fogad SSH-t. Az origin IP a `docs/14`-ben. |
| `DEPLOY_USER` | `slot4uhu` | |
| `DEPLOY_PORT` | `22` | elhagyható |
| `DEPLOY_PATH` | `~/slot4u` | az app gyökere a szerveren |
| `DEPLOY_PHP` | `/opt/cpanel/ea-php84/root/usr/bin/php` | a default PHP 8.2, ezért teljes útvonal |
| `DEPLOY_URL` | `https://slot4u.hu` | a füstteszt ezt hívja |
| `DEPLOY_SSR_PATH` | `~/ssr` | az SSR renderelő könyvtára — **az appon KÍVÜL**, mert a Passenger birtokolja (SLO-212). Elhagyható. |
| `DEPLOY_DOCROOT` | `~/public_html` | ⚠️ **NEM `~/slot4u/public`.** A webszerver ezt szolgálja ki, benne egy bridge `index.php`-vel, ami a `~/slot4u`-ból bootolja az appot. A deploy ennek az `.htaccess`-ét **ellenőrzi** (nem írja). Elhagyható. |
| `VITE_REVERB_APP_KEY` | *(a broadcast app key)* | **⚠️ lásd lent** |
| `VITE_REVERB_HOST` | `ws-eu.pusher.com` | |
| `VITE_REVERB_PORT` | `443` | |
| `VITE_REVERB_SCHEME` | `https` | |
| `VITE_APP_NAME` | `slot4u` | |
| `VITE_SENTRY_DSN` | *(a frontend Sentry projekt DSN-je)* | SLO-153. Üresen hagyva a bundle a Sentry SDK-t **le sem tölti**. |
| `VITE_SENTRY_ENVIRONMENT` | `production` | |

> ⚠️ **A `VITE_*` értékek build-time-ban ÉGNEK a bundle-be** — a szerver `.env`-je utólag
> nem javítja őket. Ezért repository-szintű variable-ök (nem environment-scope-osak):
> a `build` job jóváhagyás előtt fut, és látnia kell őket. Titkot nem tartalmaznak
> (a böngészőbe amúgy is kikerülnek).
>
> ⚠️ **A változónevek `VITE_REVERB_*`, akkor is, ha a broadcast backend hosztolt Pusher.**
> A `resources/js/lib/echo.ts` ezeket olvassa. A `docs/13` korábban `VITE_PUSHER_*`-ot írt:
> aki azt követte, **kulcs nélküli bundle-t** buildelt → a `getEcho()` némán `null`-t ad,
> és az élő foglalás-feed hibaüzenet nélkül halott. A workflow ezért **elbukik**, ha
> ezek a variable-ök üresek.

> ⚠️ A `VITE_SENTRY_DSN`-t **a szerver `.env`-jébe is** fel kell venni — nem a
> reporting miatt (az a bundle-ben van), hanem mert a CSP `connect-src`-nek
> ismernie kell az ingest hostot, különben a böngésző minden hibajelentést
> blokkol (SLO-153, docs/17 §5).

### 3.4 Szerveroldali egyszeri lépések

* `~/slot4u` git klón, origin = a repo (deploy key), `~/slot4u/public` a docroot.
* `.env`-be: `DEPLOY_HEALTH_TOKEN=…` (ugyanaz, mint a GitHub secret), valamint a
  monitoring kulcsai: `SENTRY_LARAVEL_DSN`, `VITE_SENTRY_DSN`,
  `MONITORING_HEARTBEAT_URL` (docs/17).
* A `git status` legyen tiszta — a script **elutasítja a deployt**, ha követett fájl módosult
  a szerveren (a checkout némán megenné). Untracked fájl nem akadály.
* **`~/slot4u/.htaccess.host`** (gitignore-olt, host-tulajdonú Apache direktívák, SLO-157):
  a cPanel MultiPHP a **docroot `.htaccess`-ébe** írja a PHP-handlert (`ea-php84`), ami követett
  fájl → a checkout letörölné, és az oldal visszaesne a tárhely alapértelmezett PHP-jére,
  amin az app függőségei nem futnak. A blokk ezért ebbe a fájlba kerül, és a script a checkout
  után fűzi vissza (idempotensen). A repo publikus, a blokk pedig hosting-fiók-specifikus —
  ezért nem commitoljuk.

## 4. Deploy

```bash
git tag v0.8.0-M8            # a milestone záró commitján
git push origin v0.8.0-M8
```

Majd: **Actions → Deploy → Review deployments → Approve**.

Kézi/ismételt deploy ugyanarra a tagre: *Actions → Deploy → Run workflow → ref = `v0.8.0-M8`*.

A workflow-nak van egy védőkorlátja: **csak `main`-ből elérhető commitot deployol**
(`git merge-base --is-ancestor`) — feature branch-ről tagelt kód nem mehet prodra.

## 5. Rollback

Minden deploy kiírja a job summary-ba (és a logba) az előző release-t:

```
deploy-previous-ref=v0.7.0-M7
```

**Két út, ugyanaz a kód:**

1. **GitHub-ról:** *Actions → Deploy → Run workflow* → ref = az ELŐZŐ tag, és
   **skip_migrations = true**.
2. **A szerverről** (ha a GitHub nem elérhető, vagy sürgős):
   ```bash
   cd ~/slot4u && bash deploy/rollback.sh v0.7.0-M7
   ```

A rollback szándékosan **nem futtat `migrate:rollback`-ot**: a migrációk a projektben
előre-irányúak (lefutott migrációt sosem módosítunk), tehát a régebbi kód az újabb sémával
találkozik — ez a várt irány. Ha egy release valóban nem tud futni az új sémán, a kiút a
**javító migráció**, nem a visszafelé futtatás.

Az assetekre a rollback a release manifest-pillanatképét állítja vissza
(`public/build/manifests/<tag>.json`), így a régi kód a saját bundle-jét kapja.

⚠️ **A rollback a kódot állítja vissza, az adatot nem.** Egy migráció, ami adatot ír át,
a visszaállított kód alatt is átírva marad. Adatra a mentés a kiút, nem a rollback:
**docs/18 §4**. Séma- vagy adatátíró migrációt tartalmazó release előtt érdemes egy kézi
`php artisan backup:run`-t futtatni — a napi mentés akár 24 órás lehet.

### Mit tegyél, ha a deploy elbukott

A script hiba esetén **karbantartási módban hagyja az oldalt**, és kiírja a pontos
parancsot. Sorrend: (1) rollback a fenti ref-fel, (2) füstteszt kézzel
(`DEPLOY_HEALTH_TOKEN=… bash deploy/smoke.sh https://slot4u.hu v0.7.0-M7`), (3) csak
utána vizsgáld az okot.

## 6. Füstteszt — mit bizonyít

A `deploy/smoke.sh` a publikus interneten át kérdez, mert „a script 0-val kilépett" nem
bizonyíték:

| Ellenőrzés | Mit zár ki |
|---|---|
| `GET /up` → 200 **és CSP header** (újrapróbálkozva) | az app fel sem áll — a 200 önmagában nem elég (SLO-162) |
| `GET /_deploy/health` → `release` | **nem a várt verzió szolgál ki** (rosszul sikerült checkout, régi opcache) |
| … → `commit` | **ugyanaz a NÉV, más commit** — branch-refnél a név semmit nem bizonyít (SLO-158) |
| … → `environment=production` | rossz `.env`-vel indult a konténer/host |
| … → `config_cached=true` | a deploy nem jutott el a cache-lépésig |
| … → `pending_migrations=0` | lefuttatatlan migráció, vagy elérhetetlen DB (`null` → bukás) |
| `GET /` → 200, nincs stack trace | `APP_DEBUG` bekapcsolva maradt, vagy hibaoldal a nyitólapon |
| CSP header jelen van | nem a slot4u app válaszol (parkoló oldal, edge hibalap) |
| … → `ssr_healthy=true` | **az SSR be van kapcsolva, de a renderelő nem válaszol** (SLO-212) |
| `GET /` szerver-markupjában van `<h1>` | **az oldal üres shellként ment ki** — l. §6.6 |

A `/_deploy/health` **token mögött van, és token nélkül 404** (nem 403): a futó verzió
neve támadónak hasznos, látogatónak nem — a végpont létezését sem erősítjük meg.

> ⚠️ **Hibakereséskor tudd:** ez a 404 **két különböző okot takar** — nincs kint a route
> (a deploy régebbi commitot tolt ki, mint hiszed), vagy nem stimmel a token. A füstteszt
> ezért mindkettőt kiírja; a `deploy-target-sha` a deploy logban dönti el, melyikről van szó.
A token a `DEPLOY_HEALTH_TOKEN` env-ből jön; ha nincs beállítva, **senki** nem kapja meg
(üres konfig ≠ üres token). A verziót a `deploy.sh` írja a gitignore-olt `.release`
fájlba, a `config:cache` ELŐTT — így a cache-elt configba ég, és kérésenként nem kerül
lemez-olvasásba.

### 6.1 A Cloudflare edge — ki válaszolt valójában?

Minden kérés a Cloudflare-en át megy, tehát minden válasznak **két lehetséges szerzője**
van: az app, vagy az edge előtte. A kettő megkülönböztetése nem részletkérdés — a
bot-védelmi challenge-oldal **HTTP 200, HTML törzzsel**, vagyis pontosan úgy néz ki, mint
egy egészséges válasz annak, aki csak a státuszkódot nézi. A `v0.7.3` deploynál emiatt
lett piros egy sikeres deploy CI-ja, és emiatt írt a liveness check „ok"-ot arra a
kimaradásra, amit felderíteni hivatott (SLO-162).

**A szabály:** egy check csak akkor zöld, ha a válasz **bizonyítja, hogy az apptól jött**.
A bizonyíték a `Content-Security-Policy` header: a `SecurityHeaders` middleware a globális
stack elejére van fűzve (SLO-145), tehát minden app-válaszon rajta van — az edge oldalain
viszont soha. A füstteszt ezt kéri számon a `/up`-on és a nyitólapon egyaránt.

**Ha challenge-t kapsz** (a script megnevezi: `cf-mitigated` header, `challenge-platform`
script, vagy a blokkoló oldal címe), a deployról ez **semmit nem mond**. Előbb ellenőrizd
más hálózatról (lokálisan futtatva a scriptet), és nézd meg SSH-n a `.release`-t — a
„javítás" előtt.

### 6.2 Ha challenge-t kap a runner — mi a teendő

A füstteszt minden kérése (nem csak a `/_deploy/health`) azonosítja magát:
`User-Agent: slot4u-smoke/1` és `X-Deploy-Token: <DEPLOY_HEALTH_TOKEN>`. A javítás első
lépcsője **maga ez**: a régi script `curl` alapértelmezett user agentjével kopogott, amit a
Cloudflare **Browser Integrity Check**-je (minden tervben be van kapcsolva) rutinszerűen
challenge-el. Elképzelhető, hogy ennyi elég is — ezért a sorrend: **előbb mérj, aztán
állíts**.

**1. Reprodukálás deploy nélkül.** GitHub → Actions → **Smoke test** → *Run workflow*, a
`ref` a jelenleg élő tag (pl. `v0.7.3`). Ez csak GET kéréseket küld, de a `production`
environment jóváhagyási kapuján át (a token onnan jön) — egy kattintás.

⚠️ **A `ref` azt mondja meg, minek KELL kint lennie — nem azt, melyik script fut.** A
diagnosztikai futás mindig a **dispatch branchén** lévő `deploy/smoke.sh`-t használja (alapból
`main`), mert a kérdés az, hogy a *mostani* script átjut-e a *mostani* edge-en. A deploy
pipeline szándékosan fordítva működik: ott a release-szel *együtt szállított* füstteszt fut.

Bukás esetén a workflow kiírja ugyanannak a kérésnek a válaszfejléceit **azonosítva és
névtelenül**:

* **csak a névtelen kap challenge-t** → Browser Integrity Check volt, az azonosított kérés
  már átmegy: **nincs több teendő**.
* **mindkettő challenge-t kap** → egy bot-szabály vagy a Security Level akad fel a runner
  IP-jére → 2. lépés.

**2. Ki adta a challenge-t?** Cloudflare → a zóna → **Analytics → Events**. ⚠️ **Az ingyenes
terven a megőrzés 24 óra** (és mintavételezett), tehát ezt a reprodukálás után **azonnal**
kell megnézni — utólag egy két napja történt eseményt már nem találsz. Az esemény sora
megnevezi a szolgáltatást (Bot Fight Mode / Managed rules / Security Level / Browser
Integrity Check / Rate limiting rules).

**3a. Ha NEM Bot Fight Mode** — WAF custom rule, Skip action:
Cloudflare → a zóna → **Security → WAF → Custom rules → Create rule** (újabb dashboardon
**Security → Security rules**). Kifejezés (Edit expression):

```
(http.request.headers["x-deploy-token"][0] eq "<DEPLOY_HEALTH_TOKEN>")
```

Action: **Skip** → pipáld be, ami az Events szerint elkapta (`Browser Integrity Check`,
`Security Level`, `Managed Rules`, `Rate limiting`), és a *All remaining custom rules*-t.
A szabály legyen a lista **elején**.

⚠️ **A szabály a headerre illeszkedik, NEM a user agentre.** A UA-t bárki beírja; a header
értéke titok. A UA csak azért van, hogy egy log-sorból is látszódjon, ki kopogtat.

**3b. ⚠️ Ha Bot Fight Mode** (ingyenes terv) — **azt WAF skip szabállyal NEM lehet
megkerülni.** A Bot Fight Mode nem a Ruleset Engine-en fut, tehát a Skip/Bypass/Allow
akciók nem hatnak rá; ez a Cloudflare dokumentált korlátja, nem konfigurációs hiba. Három
út van: **(a)** kikapcsolod (Security → Bots) — a `docs/12` B3 amúgy is jelezte, hogy a
Barion webhook útvonalán sem szabad blokkolnia; **(b)** Pro tervre váltasz, ahol a **Super**
Bot Fight Mode már skip-elhető; **(c)** együtt élsz vele, és a füstteszt bukását
kézzel értékeled — ez a legrosszabb, mert visszahozza a „piros CI, ami nem jelent semmit"
állapotot.

⚠️ **Ez kézi Cloudflare-beállítás**, nem a repóból jön. Amíg nincs meg, a füstteszt
elbukhat egy tökéletesen jó deploy után is — de **hangosan és megnevezve**, nem néma hamis
zölddel. A script ezért újra is próbálkozik (`SMOKE_RETRIES`): a challenge gyakran
IP-hez és perchez kötött, átmeneti.

A `deploy/smoke.sh`-nak **saját tesztje van** (`tests/Feature/Deploy/SmokeScriptTest.php`):
egy `php -S` folyamat játssza a túloldalt (`tests/Fixtures/deploy-smoke-server.php`), és a
script ugyanazzal a curl-lel beszél vele, mint élesben. Lefedve: az egészséges eset, a
challenge három álruhája, a parkoló oldal, és hogy a token-header tényleg **minden**
kérésen kimegy.

### 6.3 Kötelező platform-adat (SLO-166)

A deploy a migráció után lefuttatja a **`ProductionSeeder`**-t. Ez **csak katalógus-adatot**
tesz ki — jogosultságok, `base` plan, jutalék-konfiguráció, platform jogi dokumentumok —,
demo vagy teszt adatot **soha** (az a `DatabaseSeeder`, ami élesen nem futhat).

⚠️ **Miért került bele:** a `v0.7.4`-ig a deploy **semmit nem seedelt**. Az SLO-161 emiatt
**tétlenül landolt volna** élesben: kint volt a két új tábla, de nulla platform jogi
dokumentum, tehát a cégregisztráció **semmilyen elfogadást nem kért volna**. Minden
ellenőrzés zöld volt — az app elindult, a migrációk lefutottak, a füstteszt átment.

**Ez a hiányosságok jellemző alakja itt:** a hiányzó katalógus-adat nem hibát okoz, hanem
**udvarias semmittevést**. Ezért fut a seed **minden** deploynál, nem csak akkor, amikor
„kell": minden hívott seeder idempotens (`if (...exists()) return;`), tehát a következő
kötelező adat magától kimegy, ahelyett hogy egy kiadási jegyzetben kellene emlékezni rá —
a `v0.7.4` tag üzenetébe pont ilyen kézi emlékeztető került.

**Rollbacknál kimarad**, ugyanazért, amiért a migráció: visszafelé menet minél kevesebb
változzon.

### 6.4 A pipeline saját függőségei (SLO-164)

A workflow-k által használt actionök is függőségek, és ugyanúgy avulnak. 2026-08-21-én
mind a legfrissebb majoron van: `checkout@v7`, `setup-node@v7`, `cache@v6`,
`upload-artifact@v7`, `download-artifact@v8`.

⚠️ **Két dolog, amit egy verzióemelésnél itt mindig meg kell nézni**, mert a deploy
útvonalán vannak:

* **Az artifact-útvonal.** A `download-artifact@v5` megváltoztatta, hova csomagol ki egy
  **id szerint** kért artifactot (`path/név/` → `path/`) — a **név szerinti** letöltést
  nem érintette. Mi név szerint töltünk le, ezért ez a törés minket kikerült. Ha valaha
  id-re váltanánk, ez a sor a figyelmeztetés. Egy némán áthelyezett bundle **törött
  frontendet szállítana, miközben a füstteszt 200-at lát** — a `/` ugyanúgy válaszol.
* **A `git fetch` a checkout után.** A `checkout@v6` a megőrzött tokent a `.git/config`-ból
  egy `RUNNER_TEMP` alatti fájlba mozgatta. A hitelesített git-parancsok változatlanul
  működnek (ezt a felfelé-kompatibilitást a checkout dokumentálja), és a „csak `main`-ből
  deployolunk" kapu épp ilyen parancsra épül.

A `setup-node@v5` óta van automatikus cache, ha a `package.json`-ben van `packageManager`
mező — nálunk **nincs**, tehát az explicit `cache: npm` marad az egyetlen utasítás.

### 6.5 A demo tenantok üzemeltetése (SLO-191)

A négy sales-persona (`docs/20`) **nem a deploy része**, és szándékosan nem az: a
`ProductionSeeder` kizárólag katalógus-adatot tesz ki (6.3), demo adatot soha. A demo külön
életciklust követ.

**Először, kézzel, egyszer:**

```
php artisan demo:seed          # mind a 4 persona + a smoke tenant
php artisan demo:seed --tenant=demo-fitnesz --fresh   # egy persona újraépítése
```

⚠️ **A `fakerphp/faker` ezért `require`, nem `require-dev` (SLO-217).** A deploy `--no-dev`-vel
telepít, a `Database\Seeders\` névtér viszont a prod autoloadban van — vagyis a `DemoDataFactory`
kiment élesre, a függősége nem, és a `demo:seed` az első éles futtatáskor `Class "Faker\Factory" not
found`-dal halt meg. A demo tenantok termékfunkciók (a publikus főoldal „Próbáld ki élőben"
szekciója rájuk épül, és a `demo:reset` cron élesben rebuildeli őket), nem teszt-fixture-ök.
A `ProductionDependenciesTest` őrzi, hogy prod kódútvonal ne importáljon `require-dev` csomagot.

A parancs **bármely környezeten futtatható**, mert a destruktív útja csak `is_demo` tenantot
érhet el: a `DemoSeeder` visszautasítja a valós tenant tulajdonában lévő slugot, a
`PurgeDemoTenant` pedig a nem jelölt tenant törlését (`docs/20` §3.1). Egy éles telepítésen,
ahol soha nem futtatták, egyszerűen nincs demo tenant.

**Utána magától, minden éjjel 03:00-kor** (Europe/Budapest) a `demo:reset` — `demo:seed --fresh`
mind az 5 tenantra. A demo **írható**, tehát a látogatók összepiszkolják; ez teszi a piszkot
ingyenessé. A scheduler-bejegyzés `when` feltétele miatt **ahol nincs demo tenant, ott nem fut**.

⚠️ **Amit tudni kell róla üzemeltetőként:**

* **7 perc 40 mp** (mérve, dev MariaDB) — osztott tárhelyen több. Ez idő alatt a demo tenantok
  adatai **törlődnek és újraépülnek**; a többi tenant nem érintett.
* **Télen szűk az ablak:** 03:00 CET = 02:00 UTC, a napi backup 02:10 UTC. Ha a reset elhúzódik,
  belelóghat a mysqldumpba. Részletek és a lehetséges megoldások: `docs/20` §3.2.
* **Hibára riaszt** — `Log::error` + Sentry (`monitor: demo-reset` tag) + nem-nulla exit kód. Ha
  ilyen riasztás jön, a demo **félig felépülve** maradhatott: a `DemoSeeder` personánként külön
  tranzakciót használ, tehát a hiba előttiek megvannak, az utániak hiányoznak. A javítás mindig
  ugyanaz: `php artisan demo:reset` kézzel, a hibaüzenettel a kezedben.
* **A rollback nem érinti** — ahogy a migrációt sem (5. fejezet).

### 6.6 ⚠️ A szerver-renderelés ellenőrzése (SLO-212)

Ez az a hiba, ami **hónapokig élt anélkül, hogy bármi elromlott volna**: a publikus oldalak
üres `<div id="app">`-pel mentek ki, miközben a `docs/01` SSR-t ígért. Az Inertia némán
kliensoldali renderre esik vissza, ha a renderelő nincs meg — az oldal az embernek tökéletes,
a keresőnek üres. **Semmilyen 200-as ellenőrzés nem fogja meg.**

Ezért a füstteszt két külön dolgot kérdez, mert két külön ok:

1. **`ssr_healthy`** a `/_deploy/health`-ből: a szerver saját oldaláról nézve válaszol-e a
   renderelő. Ha nem, a Node app nem fut, vagy az `INERTIA_SSR_URL` rossz helyre mutat.
2. **A `/` válaszában van-e `<h1>`** — a **propok kiszűrése után**.

⚠️ **Az `ssr_healthy` a válasz TÖRZSÉT nézi, nem a státuszkódot** — és ezt a prod bizonyította
be. A `/_ssr`-re felmountolt renderelő **HTTP 200-zal** felelt `{"status":"NOT_FOUND"}`
törzzsel *minden* útvonalra (a Passenger nem vágja le a prefixet, az Inertia stock szervere
pedig a teljes URL-re illeszt). Egy renderelő, ami **semmit nem renderel**, de a
státuszkód-ellenőrzésnek egészséges. A `/health`-nek `{"status":"OK"}`-t kell mondania.

⚠️ **A kiszűrés nem kozmetika.** Az Inertia az egész oldalt beleírja egy
`<script data-page="app" type="application/json">` blokkba, címsorokkal együtt, a `json_encode`
pedig **nem escape-eli a `<`-t**. A nyers törzs grepje ezért találhat markupot olyan oldalon,
amin **semmi nem renderelődött** — pontosan akkor menne át, amikor buknia kellene.

⚠️ **A script-ELEMET töröljük, nem „sorvégig".** Az Inertia a props-blokkot írja ki **előbb**,
és a root divet utána (l. a saját `Directive.php`-ját), mindet egy sorban:

```html
<script data-page="app" ...>{json}</script><div id="app">…</div>
```

Egy sorvégig futó törlés tehát **magát a szerver-renderelt markupot törölte** — a check így
sosem tudott átmenni. Helyesnek látszott, átment a saját fixture-én, és az első valódi deployt
buktatta el egy tökéletesen renderelt oldalon. A fixture azóta a prod tényleges alakját viszi,
a törlés pedig `perl` nem-mohó illesztése (a `sed` ezt nem tudja kifejezni, a props JSON-ban
pedig lehet `<`). **Ha nincs `perl`, a check hangosan elbukik** — egy kiszűrés nélküli törzsön
a propok teljesítenék a grepet, ami rosszabb, mint semmilyen ellenőrzés.

⚠️ Ha az `INERTIA_SSR_ENABLED=false`, a füstteszt **egy szót sem szól** az SSR-ről. Egy tudatos
döntés nem olvasható elromlott renderelőként.

Mindezt teszt őrzi (`tests/Feature/Deploy/SmokeScriptTest.php`) egy fixture-szerver ellen,
aminek a propjaiban **szándékosan van literál `<h1>`** — a kiszűrés elhagyása pirosra vált.

### 6.7 ⚠️ A deploy preflightja SSR-hez

Két dolgot **megtagad**, még mielőtt a karbantartási mód bekapcsolna (tehát az oldal sértetlen
marad):

* **nincs `INERTIA_SSR_URL` a szerver `.env`-jében.** ⚠️ Az `INERTIA_SSR_ENABLED`
  **alapértelmezése `true`** — a szerver-renderelést eddig nem ez a kapcsoló tartotta ki,
  hanem az, hogy a bundle sosem jutott ki. A default URL (`http://127.0.0.1:13714`) ezen a
  hoszton **biztosan rossz**: a Passenger birtokolja a socketet, saját portot nem nyit.
* **nincs `/_ssr` kivétel a `DEPLOY_DOCROOT/.htaccess`-ben.** A front-controller rewrite
  különben elnyeli a renderelő alútvonalait, a Laravel 404-ezik, az Inertia pedig ebből
  csendes kliens-fallbacket csinál. A szükséges két sor a front-controller szabály **elé**:

  ```apache
  RewriteCond %{REQUEST_URI} ^/_ssr(/|$)
  RewriteRule ^ - [L]
  ```

* **üres `SSR_SHARED_SECRET`, miközben a renderelő nem loopbacken van.** A `/_ssr` a publikus
  oldalon belül lakik, tehát a `/render` az internetről hívható: titok nélkül bárki beküldhet
  egy page objectet, és a **saját domainünkről** kiszolgált HTML-t csinálunk belőle (plusz egy
  elégethető CPU). A loopback az egyetlen kivétel — ott nincs kit kizárni. A titkot **két
  helyre, azonos értékkel**: a szerver `.env`-jébe és a Node app saját környezeti változói közé
  (cPanel → az app → Environment variables).

* **`INERTIA_SSR_ENSURE_BUNDLE_EXISTS` nincs `false`-ra állítva**, miközben nincs
  `bootstrap/ssr/ssr.js` az app könyvtárában. Az Inertia a renderelő hívása **előtt** ezt nézi:

  ```php
  if (! $isHot && $this->shouldEnsureBundleExists() && ! $this->bundleExists()) {
      return null;   // ← néma kliens-fallback
  }
  ```

  …és a `BundleDetector` a `base_path('bootstrap/ssr/ssr.js')`-t keresi, **az appon belül**.
  Ezen a hoszton a bundle a renderelő saját könyvtárában van (a Passengeré), a
  `/bootstrap/ssr` pedig gitignore-olt — tehát a checkout sem hoz oda egyet. E nélkül a
  beállítás nélkül **minden más lehet helyes** (bundle kint, renderelő válaszol, titok
  stimmel), és az oldal akkor is üresen megy ki, egy szó nélkül.

A négy megtagadást teszt őrzi (`tests/Feature/Deploy/DeployScriptPreflightTest.php`), egy
eldobható könyvtár ellen futtatva a valódi scriptet.

⚠️ **Ezt a fájlt a deploy nem írja, csak ellenőrzi.** A `.htaccess.host` mechanizmus a
`~/slot4u/public/.htaccess`-t védi — **azt a fájlt, amit a webszerver el sem olvas** ezen a
hoszton. A valódi docroot a `~/public_html`, és az ottani `.htaccess` a hosting beállítása,
nem a repóé; egy deploy, ami belenyúlna, olyasmit birtokolna, ami nem az övé.

### 6.8 Az SSR bundle szállítása

Külön artefakt (`ssr-<run_id>`), és **külön rsync** a `DEPLOY_SSR_PATH`-ba, a deploy script
futása **előtt** — mert a script az, ami újraindítja a Passengert. A sorrend: előbb az új
fájlok, aztán a restart; fordítva a régi bundle szolgálna ki tovább, és **semmi nem szólna**.

* **`node_modules` nem kell.** A bundle `ssr: { noExternal: true }`-val épül, tehát viszi a
  függőségeit: ~5 MB fájl ~300 MB csomag helyett, és nincs `npm ci` a szerveren.
* **`--delete` nincs.** A `package.json` ott lakik (ez mondja meg a Node-nak, hogy a bundle
  ES-modul), és nem egy release-hez tartozik — a deploy script csak akkor írja, ha hiányzik.
* A restart `touch ~/ssr/tmp/restart.txt`.
* A bundle **code-split**, tehát nem egy fájl: `ssr.js` + `assets/` (~90 chunk, összesen ~5 MB).
  Mivel a feltöltés nem töröl, a régi chunkok gyűlnének — ezért a deploy a `~/ssr/assets`-ből
  ugyanúgy kigyomlálja a `DEPLOY_ASSET_RETENTION_DAYS`-nél régebbieket, ahogy a `public/build`-ből.
  ⚠️ **Csak az `assets/`-ből.** A `package.json` egyszer íródik és soha többé; egy az egész
  könyvtárra menő kor-alapú söprés egy hónappal az utolsó módosítása után kitörölné azt az
  egy fájlt, ami a Node-nak megmondja, hogy a bundle ES-modul.

### 6.9 ⚠️ Nyitott: a renderelő hívása a CDN-en át megy oda-vissza

Az `INERTIA_SSR_URL` prodon a **publikus URL** (`https://slot4u.hu/_ssr`), és ennek ára van:
a szerver a saját oldalait úgy rendereli, hogy **kimegy a Cloudflare bécsi edge-éig és vissza**
(`cf-ray: …-VIE`, mérve **~114 ms** a szerverről). Vagyis minden szerver-renderelt oldal
TTFB-je ennyivel nő, és egy CF-oldali WAF-szabály vagy bot-challenge **némán** kliens-renderre
fordítja az egészet — pont az a csend, ami ellen ez az issue szól. (A deploy pillanatában a
füstteszt elkapja; egy későbbi CF-szabályváltozás viszont már nem.)

Amiért mégis ez megy ki most, és nem valami közvetlenebb:

| próba | eredmény |
|---|---|
| `http://127.0.0.1/_ssr` `Host: slot4u.hu` fejléccel | **404** — a cPanel vhostjai a publikus IP-re vannak kötve, a loopbackre nincs vhost, tehát a default vhost felel; a Host fejléc szóba se kerül |
| `http://<publikus IP>/_ssr` `Host` fejléccel | **301** a https-re, vagyis vissza a CF-en át |
| `https://<publikus IP>/_ssr` közvetlenül | **hibás lánc** — az origin CF Origin CA tanúsítványt mutat, ami nem publikusan megbízható |

Mindhárom megkerüléshez vagy a hoszt `.htaccess`-ébe kellene nyúlni (nem a miénk, l. §6.7),
vagy tanúsítvány-ellenőrzést kikapcsolni, vagy saját Inertia `Gateway`-t forkolni. Külön
issue-ba tartozik, nem ebbe.

## 7. Karbantartási ablak

`artisan down` és `artisan up` között csak ez van: checkout → (composer, ha a lock változott)
→ migrate → **kötelező adat seedelése** → cache-ek. A cron-vezérelt queue worker és a scheduler **maguktól állnak** a
karbantartási mód alatt (mindkettő ellenőrzi), tehát nem fut job félig frissült kódon.

Ami **nem** része ennek a megoldásnak: valódi zero-downtime. Ahhoz release-könyvtárak és
`current` symlink kellene, ami a cPanel docrootjának átállítását igényli **két helyen**
(az apex `~/public_html` bridge ÉS a `*` wildcard vhost) → **SLO-156**.

## 8. Kapcsolódó

* `docs/13` — a kézi deploy elődje és a hosting-sajátosságok (gitignore-olt).
* `docs/18` — backup és restore: mit ment a napi futás, és hogyan áll vissza egy adatvesztés.
* `docs/20` — a demo tenantok és az éjszakai `demo:reset` (6.5).
* **SLO-156** — staging + zero-downtime release-könyvtárak.
* **SLO-148** — `composer audit` / `npm audit` a CI-ban.
