# 19 — Adatvédelem: érintetti jogok, adatexport és törlés

> **Státusz:** az SLO-159 (SLO-48 1/3) szállította az érintetti jogok technikai
> oldalát, az **SLO-160** (2/3) a **megőrzési időket és az automatikus
> takarítást** (§7). A **verziókövetett hozzájárulás és a cookie consent** az
> **SLO-161**-é — az is ebbe a dokumentumba fog beépülni.
>
> ⚠️ Ez a fájl a **technikai** megvalósítást írja le. Az ÁSZF és az adatkezelési
> tájékoztató **szövegéhez jogász kell**; a slot4u nem ad jogi tanácsot.

## 1. Ki kicsoda — ez határoz meg mindent

| Szerep | Ki | Mit tesz |
|---|---|---|
| **Adatkezelő** (controller) | a **tenant** | eldönti, milyen célból kezeli az ügyfelei adatait; ő felel az érintett felé |
| **Adatfeldolgozó** (processor) | a **slot4u** | a tenant utasítására tárol és feldolgoz |
| **Érintett** (data subject) | a tenant **ügyfele** | kérheti az adatai másolatát és a törlésüket |

Ebből egyetlen, mindent eldöntő következmény adódik: **a slot4u nem törölhet az
adatkezelő helyett.** Ezért az export önkiszolgáló, a törlés viszont *kérelem*,
amit a tenant bírál el. Egy „töröld magad most" gomb azt jelentené, hogy a
platform az adatkezelő háta mögött hoz döntést olyan adatokról, amelyekre a
tenantnak saját megőrzési kötelezettsége lehet.

## 2. Adatexport (GDPR 15. cikk)

* **Hol:** members area → `/my/privacy` → „Adataim letöltése" (`GET /my/privacy/export`)
* **Mit ad:** egy JSON fájl, benne a profil, foglalások (+ státusz-előzmény),
  ajánlatkérések + üzenetek, várólista-helyek, fizetések + visszatérítések,
  számla-metaadatok, a címzettre küldött értesítések, és a korábbi adatvédelmi
  kérelmek. Építője: `App\Services\Privacy\PersonalDataExport`.
* **Azonnal, nem emailben.** Az adatkészlet egy ügyfél saját rekordjai, tehát
  kicsi; egy emailben küldött link viszont egy továbbítás után már valaki másé.
* **Egy tenant, egy fájl.** Ugyanaz a személy több tenantnál is lehet ügyfél —
  azok külön adatkezelők. Egy összevont fájl elárulná mindegyiküknek, hogy a
  másik létezik.
* **Throttle 6/perc:** a válasz minden hívásnál újraépíti a teljes adatképet.
* **Nincs benne:** jelszó-hash és remember token (kifelé adni hitelesítő adatot
  önmagában kockázat), valamint a `quote_requests.internal_notes` — az a tenant
  saját értékelése, nem az érintett adata (15. cikk (4)).

Minden letöltés bekerül a nyilvántartásba (`privacy_requests`, azonnal
`completed` státusszal) és az audit naplóba (`privacy.data_exported`): egy teljes
személyesadat-készlet kiadása maga is adatközlés.

## 3. Törlés (GDPR 17. cikk)

### 3.1 A folyamat

1. Az ügyfél a `/my/privacy` oldalon beküldi a kérelmet (`POST /my/privacy/erasure`).
   Indoklást **nem kérünk** — a 17. cikk a szokásos esetben nem követeli meg, és
   egy szabadszöveges mező csak újabb doboznyi személyes adat lenne.
2. A kérelem `pending` állapotban bekerül a tenant sorába (`/settings/privacy`,
   `privacy.manage`). Amíg nyitva van, **újabb kérelem nem keletkezik** — a
   duplaklikk nem csinál második feladatot.
3. A tenant **végrehajtja** vagy **elutasítja**. Az elutasításhoz **kötelező
   indoklás** (12. cikk (4): az érintettet tájékoztatni kell az okról), és az
   elutasított kérelem a nyilvántartásban marad — épp azt kell tudni utólag
   igazolni.
4. Egy már elbírált kérelem nem nyitható újra (`403`): a nyilvántartás értéke
   pontosan abból ered, hogy nem írható át.

### 3.2 Mi történik a végrehajtáskor

`App\Services\Privacy\AnonymizeCustomer` — **felülír, nem töröl**, egyetlen
tranzakcióban:

| Adat | Mi lesz vele |
|---|---|
| `users` sor | név → a lang fájl helyettesítő szövege, email → `anonymized-{id}@invalid` (RFC 2606, kézbesíthetetlen), telefon → `null`, jelszó → véletlen (senki nem ismeri), `email_verified_at`/`remember_token` → `null`, `anonymized_at` → most |
| `sessions` | a user sorai törölve — a törlés kilépteti mindenhonnan |
| `bookings` | `guest_name/email/phone`, `notes`, `cancel_reason`, `reject_reason` → `null`. **Idő, szolgáltatás, státusz, ár marad.** |
| `quote_requests` | guest mezők, `internal_notes`, `parameters` → `null` |
| `quote_request_messages` | az ügyfél saját üzeneteinek szövege helyettesítő szövegre cserélve (a szál szerkezete marad) |
| `waitlist_entries` | **törölve** — egy várólista-hely élő ígéret arra, hogy valakit megkeresünk |
| `notifications_log` | `recipient` → `redacted`; a sor marad (a dedup-kulcsokat viszi, törlésük feltámaszthatna egy értesítést) |

⚠️ **A user sort nem töröljük.** A `bookings.customer_id` FK törlése magával
vinné a foglalásokat, azzal a tenant forgalmát — ami a **jutalék alapja**
(docs/10 §3.1). Egy törlési kérelem így visszamenőleg átírná, mennyivel tartozik
a tenant a slot4u-nak.

### 3.3 A két tudatos kivétel

Ezek **nem kifelejtett esetek**, hanem a szabály korlátai — a kódban és a
tesztben is explicit módon szerepelnek:

1. **Kiállított számla.** Az `invoices` sorban nincs névoszlop, de a tárolt
   **PDF-ben van**, és a számla számviteli bizonylat: **8 év megőrzés**
   (Szt. 169. §). A 17. cikk (3) b) pontja pontosan ezt engedi. A sor és a PDF
   érintetlen marad.
2. **Audit napló.** Az `audit_logs` a *staff* műveleteinek biztonsági
   nyilvántartása, önálló jogalappal és saját megőrzési ablakkal (SLO-160). A
   jelenlegi `AuditAction` kódok egyike sem tárol ügyfél-elérhetőséget, és a
   törlés a **saját** bejegyzését is úgy írja, hogy **nem másolja bele a törölt
   értékeket** — egy ilyen sor visszatenné, amit épp kivettünk.

### 3.4 A helyettesítő szöveg a lang fájlból jön

Az anonimizált ügyfél neve (`app.privacy.erased_customer`) **a tenant nyelvén,
egyszer** íródik az adatbázisba. Azért nem futásidejű címke, mert minden
ügyféllistát megjelenítő admin képernyő nyersen rendereli a nevet — mindegyiket
utólag lokalizált címkére átírni sokkal nagyobb változás lenne, egy képernyő
garantáltan kimaradna belőle. A `users.anonymized_at` az a gépi jel, amire bármi
építhet. A lang érték módosítása a **már** anonimizált sorokra visszamenőleg nem
hat.

## 4. Tenant-izoláció

`privacy_requests` `BelongsToTenant`-os, tehát idegen kérelem-id **404** (nem
403 — docs/01: egy kereszt-tenant próbálkozás nem erősítheti meg a rekord
létezését). Az export és az anonimizálás minden lekérdezése **kifejezetten** is
szűr `tenant_id`-ra, nem csak a global scope-ra hagyatkozik: mindkettő futhat
tenant-kontextus nélküli környezetben (queue, parancs), ahol a scope néma.

## 5. Bizonyíték

A `tests/Feature/Security/PersonalDataErasureTest.php` **az élő sémán söpör
végig**: minden tábla minden szöveges oszlopát megvizsgálja a törölt névre,
emailre és telefonszámra. Egy új, személyes adatot tároló oszlop tehát a
megjelenése napján megbuktatja a tesztet, nem akkor, amikor egy hatóság rákérdez.
A söprés harness-e külön teszttel van validálva (a törlés **előtt** találnia
kell), különben „nulla oszlopon" sikerülne.

## 7. Megőrzési idők és az automatikus takarítás (SLO-160)

A 2–3. szakasz arról szól, mit tehet az érintett *kérésre*. Ez a szakasz arról,
ami **kérés nélkül, magától** történik: ami már nem kell, annak el kell tűnnie.

### 7.1 A megőrzési ablakok

Mind egy helyen: **`config/privacy.php`**. Szándékosan **nem env-vezérelt** — egy
megőrzési idő dokumentált jogi álláspont, nem környezetenkénti kapcsoló, és egy
`.env`-elgépelés olyan adatot semmisítene meg, amit egyetlen visszaállítás sem
tesz a helyére.

| Adat | Ablak | Mi történik | Miért ennyi |
|---|---|---|---|
| **Archivált tenant** | 90 nap | anonimizálás (§7.2) | `docs/01`, `docs/03` §105 óta ígért ablak |
| `notifications_log.recipient` | 90 nap | **redaktálás** (`redacted`) | a sor a dedup-kulcsot hordozza (§7.3) |
| `audit_logs.ip_address` | 90 nap | nullázás | a napló túléli az IP-t, ami nyomozáshoz sokkal hamarabb használhatatlan |
| `audit_logs` sor | 730 nap | törlés | 2 év minden reális „ki írta át?" vizsgálatot lefed |
| `sessions` | 30 nap tétlenség | törlés | user id + IP + user agent; a Laravel saját gc-je lottó |
| `integration_logs` | 90 nap | törlés | `docs/06` — ⚠️ **a tábla még nem létezik**, l. §7.5 |
| `password_reset_tokens` | Laravel `auth:clear-resets` | törlés | percekben mérhető élettartamú személyes adat |

Végrehajtó: **`privacy:retention-sweep`**, naponta **03:30 UTC** — a mentés
(02:10, `docs/18`) *után*, hogy egy tenant utolsó mentése egy teljes ablakkal
előzze meg a törlést, ne pár perccel. Minden lépés felülírás vagy már lejárt
sorok törlése, tehát **idempotens**; egy megszakadt futást egyszerűen pótol a
következő. A lépések függetlenek: amelyik nem tud lefutni, nem állítja meg a
többit, viszont **„skipped"-ként jelenik meg** — a némán nulla sor
megkülönböztethetetlen lenne a „minden rendben"-től.

### 7.2 Az archivált tenant: anonimizálás, NEM törlés

Az „archiválás után 90 nappal töröljük a tenantot" kézenfekvő olvasata a hard
delete, és **ez a rossz olvasat**. A `tenants` sor kaszkádol a foglalásokra, a
foglalások viszont a forgalmat hordozzák, amiből a slot4u jutaléka számolódik
(`docs/10` §3.1). Egy hard delete tehát **visszamenőleg átírná a slot4u saját
bevételi történetét**, és árván hagyná a már kiállított jutalékszámlákat —
amiket mindkét fél 8 évig köteles megőrizni (Szt. 169. §). Ugyanaz a logika,
mint az ügyfél-törlésnél (§3.2), csak tenant-léptékben.

Ezért a purge (`App\Services\Privacy\PurgeTenant`) **a vázat meghagyja** — a
tenant sorát, a foglalások idejét/árát/státuszát, a kiállított számlákat és a
PDF-eket —, és **minden személyre utaló adatot elvesz**:

* minden felhasználó (ügyfél **és** dolgozó) profilja anonimizálódik — a közös
  `AnonymizeUserProfile`-lal, hogy egy új PII-oszlop ne csak az egyik ágon
  essen ki;
* a foglalások és ajánlatkérések vendég-oszlopai, jegyzetei, indoklásai;
* a `staff` név/titulus/bio/fotó, a `locations` telefon és cím;
* a tenant kapcsolati blokkja a `settings`-ből, a `branding`, és az
  **`invoicing` (a számlázó API kulcs)** — egy távozott cég hitelesítő adatának
  semmi keresnivalója az adatbázisban, titkosítva sem;
* a várólista-helyek **törlődnek** (élő ígéret a megkeresésre).

**Megmarad** a tenant `name`/`slug` (a jutalékszámlák másik felét meg kell tudni
nevezni), a foglalások üzleti adatai, a `invoices` + `commission_invoices` és az
audit napló.

A purge **tenant-szintű bulk művelet, nem `users`-ciklus**: egy vendég-foglalás,
amit sosem regisztrált ember adott le, egyetlen user sorhoz sem kötődik, tehát
egy ciklus állva hagyná.

**Verseny és megszakítás.** Az egész purge egy tranzakció, ami a tenant sorára
vett zárral indul és **újraellenőrzi az összes feltételt**. Egy párhuzamos
visszaállítás sima `UPDATE tenants`, tehát erre a zárra vár: vagy előbb ér oda
(és akkor kihagyjuk, mert a tenant már nem archivált), vagy utóbb. Ha a folyamat
menet közben meghal, a tranzakció **egyben gördül vissza**, és a következő
futás elölről csinálja — ezért kerül a `tenants.purged_at` **utolsóként**.

### 7.3 Miért redaktálunk a naplókban, és miért törlünk

* A **`notifications_log` sorai maradnak**: a `(tenant_id, type, dedupe_key)`
  egyediség az, ami egy értesítést pontosan-egyszerivé tesz. Egy sor törlése
  **feltámaszthat egy kiküldést**. Személyes adat benne csak a `recipient`,
  tehát csak az megy. ⚠️ **Következmény:** a 15. cikkes export a címzett szerint
  párosít, tehát 90 napnál régebbi értesítés már nem szerepel az ügyfél saját
  másolatában. Ez maga az adatminimalizálás, nem az export hibája.
* Az **`audit_logs` sorait törölhetjük**, mert semmi nem függ a létezésüktől. A
  730 nap szándékosan rövidebb a 8 éves számviteli ablaknál: a **számviteli
  bizonyíték a számla, nem az audit sor**, ami megemlíti — a számla és a PDF
  amúgy is marad (§3.3).

### 7.4 Tenant adatexport kilépéskor

A 90 nap csak akkor tisztességes, ha a tenant **el tudja vinni a sajátját**.

* **Tenant oldalon:** `/settings/privacy` → „Adatexport letöltése"
  (`GET /settings/privacy/export`), `privacy.manage` mögött. Egy streamelt JSON:
  helyszínek, termek, dolgozók, kategóriák, szolgáltatások, ügyfelek,
  foglalások, ajánlatkérések + üzenetek, várólista, fizetések, számlák és a
  jutalékszámlák. Kurzorból íródik, soronként — egy forgalmas tenant története
  nem az a dolog, amit egy osztott tárhelyen memóriában rakunk össze.
* **Nincs benne:** a `tenants.invoicing` (a modell dekódolná a szolgáltatói API
  kulcsot egyenesen a fájlba), a jelszó-hashek és remember tokenek, valamint az
  audit napló (az a slot4u platform-szintű biztonsági nyilvántartása, saját
  jogalappal — nem tenant-tulajdon).
* ⚠️ **Az archiválás pillanatától a tenant aldoménje 404**, tehát pont akkor nem
  éri el a saját exportját, amikor kellene. Ezért ugyanaz az export a
  **superadmin oldalon is elérhető** (`GET /tenants/{tenant}/export`,
  `withTrashed`): a türelmi idő alatt a slot4u kérésre kiadja. Az archiválási
  értesítő pontosan erre irányít.
* Az **archiválási értesítő** (`TenantArchivedNotification`) a
  `ChangeTenantStatus` Actionbe van kötve, nem a controllerbe — így egyetlen
  jövőbeli belépési pont sem tud némán archiválni. Megnevezi a **pontos
  törlési dátumot** a tenant időzónájában, hogy a számlák megmaradnak, és hogy
  hogyan kérhető még másolat. Csak az archiváltba *belépéskor* megy ki: egy
  már archivált tenant újraarchiválása nem küldhet egy második, későbbi
  határidőt, mert a söprés továbbra is az eredeti `deleted_at`-ből számol.

### 7.5 Amit ez a szakasz NEM old meg

* Az **`integration_logs` tábla nem létezik** — a `docs/02` specifikálja, a
  `docs/06` 90 napot ír rá, de az M6 fizetés-munka nélküle szállt le. A söprés
  lépése **készen áll** és a tábla első napjától érvényesíti az ablakot; addig
  „skipped"-et jelent. Ha a tábla megszületik, ebben nincs teendő.
* A **fájl-logok** rotációja (`laravel.log`, méret/lemez) az **SLO-155** — más
  probléma, ne csússzon össze ezzel.

## 8. Bizonyíték a retentionre

* `tests/Feature/Privacy/TenantPurgeTest.php` — ugyanaz az **élő sémán söprő**
  harness (`Tests\Fixtures\PersonalDataSweep`), amit a §5 használ, csak most a
  tenant összes emberére: ügyfél, dolgozó **és a sosem regisztrált vendég**. A
  harness validálva (a purge előtt találnia *kell*), és a 89 vs. 91 napos
  határeset mindkét irányban tesztelt. A verseny (visszaállítás a söprés alatt)
  külön eset, a zár alatti újraellenőrzés pedig közvetlenül is.
* `tests/Feature/Privacy/RetentionWindowsTest.php` — minden ablak a saját
  határán (89/91, 729/731 nap), a chunkolt törlés több körön át, és hogy a
  session-vágás **soha nem megy közelebb a `session.lifetime`-nál** (egy
  megőrzési beállítás nem léptethet ki egy bejelentkezett felhasználót).
* `tests/Feature/Privacy/TenantDataExportTest.php` — a fájl érvényes JSON, csak
  a saját tenant adatait tartalmazza, és **nincs benne a számlázó API kulcs**.
* `tests/Feature/Privacy/TenantArchiveNoticeTest.php` — az értesítő kimegy, a
  határidőt a tenant időzónájában nevezi meg, és nem megy ki kétszer.

## 9. Ami még hátravan

| Mi | Hol |
|---|---|
| Verziókövetett ÁSZF/adatkezelési elfogadás, cookie consent | **SLO-161** |
| ÁSZF + adatkezelési tájékoztató szövege | **jogász** |
| `integration_logs` tábla (a retention-lépés már megvan) | `docs/02`, `docs/06` |
