# 18. Backup és restore — mit mentünk, hova, és hogyan állunk vissza

> Állapot: 2026-08-10 (SLO-154). Commitolható: nevek és eljárások, titok nélkül.
> A bucket-kulcsok és a mentés jelmondata a prod `.env`-ben élnek (l. §2).

## 1. Miért

Eddig **nem volt saját mentésünk**. Ami volt, az a Tárhely.Eu fiók-szintű mentése — az
viszont **nem offsite**: ha a fiók elvész, kompromittálódik, vagy a szolgáltató zárja le,
a mentés vele megy. Egy foglalási rendszernél az adatvesztés nem kellemetlenség: a tenant
összes jövőbeni bevétele van a `bookings` táblában.

A mentés két, eltérő természetű dolgot véd:

| Artifact | Mit tartalmaz | Újraépíthető-e? |
|---|---|---|
| `database.sql.gz` | minden foglalás, ügyfél, tenant, jutalék-tétel, audit napló | **nem** |
| `storage.tar.gz` | tenant logó/borítókép, kiállított számla-PDF-ek (`storage/app`) | **nem** (az OG-képek igen, de olcsóbb velük menteni) |

A kód **nem** része a mentésnek: az a GitHubon van, és a deploy pipeline (docs/16) bármikor
visszateszi. A `.env` **szándékosan nincs** a mentésben — l. §7.

## 2. Beállítás (egyszeri, Daniel)

> ### ⚠️ Jelenlegi éles állapot (2026-08-19): LOKÁLIS mentés, nem offsite
>
> A prod **`BACKUP_DISK=backup-local`** módban fut: a napi mentés a
> `~/slot4u/storage/backups` alá íródik, titkosítva, ugyanazzal a kóddal és
> retencióval — de **ugyanazon a gépen, amit véd**.
>
> **Miért:** amíg nincs éles ügyfél, Daniel nem vesz igénybe újabb szolgáltatót
> (döntés: 2026-08-19). Ez tudatos, átmeneti kompromisszum.
>
> **Mi ellen véd így is:** elrontott migráció, véletlen tömeges törlés,
> félresikerült deploy, a retention-söprés hibája — vagyis a *valószínű*
> katasztrófák ebben a fázisban. **Mi ellen NEM:** a tárhely-fiók elvesztése,
> lemezhiba, a fiók feltörése. Pontosan ezért nem ez a végállapot.
>
> **Váltás offsite-ra:** az alábbi §2 lépései, plusz `BACKUP_DISK=s3` (vagy a sor
> törlése — az `s3` az alapértelmezés). Kódváltozás **nincs**; a `backup:run`,
> a retention, a `monitor:health` checkje és a visszaállítási eljárás azonos.
> Az első éles ügyfél előtt elvégzendő — **SLO-163**.

1. **S3-kompatibilis bucket** egy olyan szolgáltatónál, amelyik **nem** a webhoszt.
   A bucket legyen **privát**, verziózás nélkül is elég.
   ⚠️ **A Wasabi minimum 1 TB-ot számláz** akkor is, ha pár MB-ot töltesz fel — ehhez
   a mérethez rossz választás. A **Backblaze B2**-nél nincs ilyen minimum, a
   **Cloudflare R2** pedig eggyel kevesebb szolgáltatói fiók, mert a Cloudflare
   már a stackben van (DNS + custom domain). Mindkettő S3-kompatibilis, csak az
   `AWS_ENDPOINT` és a régió más (R2-nél `AWS_DEFAULT_REGION=auto`).
2. Külön **application key**, csak erre a bucketre, írás+listázás+törlés joggal.
   (Törlés kell: a retention takarít.)
3. Prod `.env`:

   ```dotenv
   AWS_ACCESS_KEY_ID=...
   AWS_SECRET_ACCESS_KEY=...
   AWS_BUCKET=slot4u-backups
   AWS_DEFAULT_REGION=eu-central-003          # B2: a bucket régiója
   AWS_ENDPOINT=https://s3.eu-central-003.backblazeb2.com
   AWS_USE_PATH_STYLE_ENDPOINT=false

   BACKUP_PASSPHRASE=<openssl rand -base64 32>
   ```

4. ⚠️ **A jelmondatot tedd be egy jelszókezelőbe is.** A `.env`-ben él, a `.env` pedig nincs
   a mentésben — ha a szerver elvész, a jelmondat vele veszne, és a mentés visszafejthetetlen
   lenne. Egy visszafejthetetlen mentés pontosan annyit ér, mint a semmi.
5. `php artisan config:cache`, majd egy kézi próba: `php artisan backup:run`, utána
   `php artisan backup:list`.

Jelmondat nélkül a rendszer **működik**, csak gyengébb: a dumpot ilyenkor egyedül az védi,
hogy a bucket privát. A `backup:run` ezt minden futásnál kiírja.

## 3. Mi fut, mikor

* **`php artisan backup:run`** — ütemezve **naponta 02:10 UTC-kor** (`routes/console.php`),
  a percenkénti `schedule:run` cronon át (SLO-125 profil, nincs daemon).
* Lépések: dump → **helyi ellenőrzés** → titkosítás → feltöltés → **méret-ellenőrzés a
  túloldalon** → `manifest.json` → életjel → retention.
* **`php artisan backup:list`** — mi van kint, mekkora, és teljes-e.
* Destináció-struktúra:

  ```
  backups/production/2026-08-10_021000/database.sql.gz.enc
                                      /storage.tar.gz.enc
                                      /manifest.json          ← ha ez megvan, a futás teljes
  ```

* **Retention:** `BACKUP_KEEP_DAILY` (alap 14) napig minden nap, utána ISO-hetenként egy,
  `BACKUP_KEEP_WEEKLY` (alap 8) hétig. A **legfrissebb mentést semmilyen beállítás nem
  törli**, és a takarítás **csak sikeres feltöltés után** fut.

Amit a `backup:run` **nem** hisz el:

* a `mysqldump` nullás kilépési kódját (a pipeline `set -o pipefail` alatt fut, és a kész
  archívumot visszaolvassuk a `-- Dump completed` sorig),
* a feltöltés sikerét (a byte-méretet a célnál ellenőrizzük),
* és azt, hogy holnap is futni fog (l. §6).

## 4. Restore — adatbázis

Bármely gépen, ahol van `openssl`, `gzip` és `mysql` kliens. **Nem kell hozzá se a repó,
se futó PHP.**

```bash
# 1. Töltsd le a futás könyvtárát (rclone/aws cli/webes felület), majd:
sha256sum database.sql.gz.enc        # hasonlítsd a manifest.json-hoz

# 2. Visszafejtés (pontosan ezek a paraméterek; a manifest.json is kiírja őket)
read -rs SLOT4U_BACKUP_PASSPHRASE && export SLOT4U_BACKUP_PASSPHRASE
openssl enc -d -aes-256-cbc -md sha256 -pbkdf2 -iter 100000 -salt \
  -pass env:SLOT4U_BACKUP_PASSPHRASE \
  -in database.sql.gz.enc -out database.sql.gz

# 3. ÜRES céladatbázis (soha ne a futó éles sémára öntsd rá)
mysql -h HOST -u USER -p -e \
  "create database slot4u_restore character set utf8mb4 collate utf8mb4_unicode_ci;"

# 4. Import
set -o pipefail
gzip -dc database.sql.gz | mysql -h HOST -u USER -p slot4u_restore

# 5. Ellenőrzés
mysql -h HOST -u USER -p -N -e \
  "select count(*) from slot4u_restore.bookings; select count(*) from slot4u_restore.migrations;"
```

Éles átállításnál a `.env` `DB_DATABASE`-ét kell átírni a visszaállított adatbázisra
(+ `php artisan config:cache`), **nem** a régit felülírni: amíg a régi sértetlenül megvan,
a döntés visszavonható.

Ha titkosítás nincs bekapcsolva, a 2. lépés kimarad (a fájl neve ekkor `.enc` nélküli).

## 5. Restore — feltöltött fájlok

```bash
openssl enc -d -aes-256-cbc -md sha256 -pbkdf2 -iter 100000 -salt \
  -pass env:SLOT4U_BACKUP_PASSPHRASE -in storage.tar.gz.enc -out storage.tar.gz
tar -tzf storage.tar.gz | head          # nézd meg, mit tartalmaz
tar -xzf storage.tar.gz -C ~/slot4u/storage/app
php artisan storage:link                # ha a public/storage symlink hiányzik
```

## 6. Restore-próba jegyzőkönyv

**Az AC nem az, hogy „van mentés", hanem hogy egy mentést tényleg visszaállítottunk.**

### 6.1 Első próba — 2026-08-10, fejlesztői Docker (MariaDB 11, WSL2)

* Mentés: `backup:run` a `backup-local` diszkre, jelmondattal, futás `2026-08-10_183608`.
* Forrás DB: 49 tábla, 54 migráció, 41 tenant, 8 user, 4 foglalás, 12 audit-bejegyzés.
* Titkosított dump: **16 432 byte**; fájl-archívum: 17 360 byte.

| Lépés | Idő |
|---|---|
| 1. `sha256sum` ellenőrzés | 0,01 s |
| 2. visszafejtés (`openssl enc -d`) | 0,22 s |
| 3. üres céladatbázis létrehozása | 1,08 s |
| 4. import (`gzip -dc \| mysql`) | **5,18 s** |
| 5. `storage/app` fa visszaállítása | 0,15 s |
| **Teljes** | **~6,6 s** |

Ellenőrzés a visszaállított adatbázison:

* 49 tábla, és **minden vizsgált tábla sorszáma egyezett** a forrással
  (`tenants`, `users`, `bookings`, `services`, `locations`, `commission_invoices`,
  `audit_logs`, `migrations`).
* A foglalási kódok tételesen megvannak (`2NBJGS8N`, `NTAFAP6Z`, `Z9RXHSNC`, `ZNZ2C8X2`).
* **Az alkalmazás elindul rajta:** `DB_DATABASE=slot4u_restore php artisan migrate:status`
  → minden migráció `Ran`, függőben egy sincs; `php artisan db:table bookings` a
  visszaállított sémát olvassa.
* A fájl-archívumból 5 fájl bomlott ki hiánytalanul.

> ⚠️ **Egy ÜRES környezet felhúzásánál a visszaállítás önmagában nem elég** (SLO-166).
> Egy adatbázis-visszaállítás mindent visszahoz, de egy nulláról épített környezetnél
> (staging, új szerver) a **kötelező platform-adat** külön lépés:
> `php artisan db:seed --class=ProductionSeeder --force`. A deploy ezt magától megteszi,
> egy kézi felhúzás nem. A hiány **néma**: az app elindul, a füstteszt zöld, csak épp nincs
> jogosultság-katalógus, nincs `base` plan, nincs jutalék-konfiguráció, és a regisztráció
> nem kér elfogadást.

### 6.2 Amit ez a próba NEM fedett

* **A letöltés az S3-ról** — ahhoz éles bucket-kulcs kell. A kód ugyanaz
  (`BackupDestination` egy Laravel diszket hív), de a hálózati láb és a szolgáltatói
  jogosultságok csak élesben mérhetők. **Ez az első éles `backup:run` után pótolandó**,
  és a mérést ide kell írni.
* **Éles méretű adat.** A dev DB pár száz sor; a prod import ideje ezzel nem becsülhető,
  csak a nagyságrend (a lépések sorrendje és a szűk keresztmetszet — a 4. lépés — ugyanaz).

### 6.3 Mikor kell újra elvégezni

* Az első éles mentés után (a valódi S3-lábbal, valódi adatmennyiséggel).
* Minden **fél évben**, és minden olyan változás után, ami a séma dumpolhatóságát érinti
  (DB-verzió váltás, hoszting-költözés).
* A jegyzőkönyv új szakaszként kerül ide, a régit **nem** írjuk felül: az időtrend maga is
  információ.

## 7. Amire figyelni kell

* **A `.env` nincs a mentésben, és ez szándékos.** Benne vannak a bucket kulcsai — egy olyan
  mentés, ami a saját magához való hozzáférést is tartalmazza, körkörös, és egy kiszivárgott
  mentés azonnal a többi mentést is odaadná. A `.env` helye: jelszókezelő.
  Amit a helyreállításhoz külön őrizni kell: `APP_KEY`, `BACKUP_PASSPHRASE`,
  `DEPLOY_HEALTH_TOKEN`, `APP_ORIGINAL_HOST_SECRET`, SMTP- és Pusher-kulcsok.
  ⚠️ **`APP_KEY` nélkül a titkosított oszlopok olvashatatlanok maradnak** akkor is, ha a
  dump tökéletes.
* **A mentés cél-diszkje soha ne legyen a `storage/app` fán belül** — a következő futás
  betenné az előzőt a saját archívumába. A `backup-local` diszk ezért a
  `storage/backups` alatt van (`config/filesystems.php`).
* **A jutalékszámla-PDF-ek a `storage/app/private` alatt élnek**, tehát a fájl-archívum
  része — de ezek jogilag is megőrzendő dokumentumok, nem csak kényelmi másolatok.
* A `mysqldump` **tárolt eljárást és eventet nem ment** (nincs is ilyenünk: minden séma
  migrációból jön). Ha valaha lesz, a `--routines --events` kapcsolót fel kell venni.

## 8. Monitoring — mi szól, ha elhal

A `monitor:health` (docs/17) **negyedik ellenőrzése a mentés kora**: ha az utolsó sikeres
futás `BACKUP_STALE_AFTER_HOURS`-nál (alap 36 óra) régebbi, a check bukik → Sentry-riasztás,
és a dead man's switch pingje **elmarad**, tehát a külső monitor is szól.

Az ellenőrzés csak ott fut, ahol a mentés **be van állítva**: dev és CI nem kap riasztást
arról, hogy nincs offsite bucketje. Azt, hogy éles gépen legyen, a §2 checklist őrzi.

Ez a fajta riasztás a lényeg: egy mentés, ami hibázik, magától kiabál — egy mentés, ami
**csendben abbahagyja a futást**, csak akkor derül ki, amikor szükség lenne rá.
