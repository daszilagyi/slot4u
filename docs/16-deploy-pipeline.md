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
* **SLO-156** — staging + zero-downtime release-könyvtárak.
* **SLO-148** — `composer audit` / `npm audit` a CI-ban.
