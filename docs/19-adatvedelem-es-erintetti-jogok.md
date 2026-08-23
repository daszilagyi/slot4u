# 19 — Adatvédelem: érintetti jogok, adatexport és törlés

> **Státusz:** az SLO-159 (SLO-48 1/3) szállította az érintetti jogok technikai
> oldalát, az **SLO-160** (2/3) a **megőrzési időket és az automatikus
> takarítást** (§7), az **SLO-161** (3/3) a **verziókövetett hozzájárulást**
> (§10), az **SLO-165** pedig a **cookie consentet** (§11).
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
| ÁSZF + adatkezelési tájékoztató **szövege** | **jogász** |
| `integration_logs` tábla (a retention-lépés már megvan) | `docs/02`, `docs/06` |

## 10. Verziókövetett hozzájárulás (SLO-161, GDPR 7. cikk (1))

A 7. cikk (1) szerint az adatkezelőnek **igazolnia kell tudnia**, hogy az érintett
hozzájárult. Ehhez nem elég egy boolean flag: azt kell tudni megmondani, **melyik
szöveg melyik változatát** fogadta el valaki, mikor és honnan.

### 10.1 Két szint, mert két szerződés van

A §1 következménye a séma is:

| `legal_documents.tenant_id` | Mi ez | Ki fogadja el |
|---|---|---|
| `NULL` | a **platform** ÁSZF-je és tájékoztatója | a **tenant** (a cég, ami regisztrál) |
| kitöltve | a **tenant saját** dokumentuma | a tenant **ügyfelei** |

A tenant az adatkezelő, tehát a **szöveg az övé**: a slot4u a gépezetet adja
(verziózás, elfogadás-rögzítés, újra-elfogadás), nem a szavakat. Ha egy tenant még
nem tett közzé semmit, **sehol nem kérünk elfogadást** — a foglalási űrlap
letiltása egy olyan beállítás miatt, amiről a tenant még nem tud, működő oldalt
állítana le.

### 10.2 Az alany user VAGY email

⚠️ A tábla neve `legal_consents`, **nem `user_consents`**. A belépési pontok fele
vendégként fut (`bookings.guest_email`, SLO-128), user sor nélkül — egy `user_id`-ra
kulcsolt tábla ezeket **rögzítetlenül hagyná, miközben teljesnek látszik**. Ez a
lehető legrosszabb kimenet olyasminél, aminek az egyetlen dolga, hogy bizonyíték
legyen.

### 10.3 Hét belépési pont

Tenant regisztráció (központi domain) · ügyfél regisztráció · foglalás · megrendelés
(`no_time_slot`) · esemény-jelentkezés · **várólista** · ajánlatkérés.

A rögzítés **a kísért dolog létrejötte után** történik: egy elfogadás, amit egy
később elbukó foglalás hagyott hátra, rosszabb a semminél, mert azt sugallja, valaki
beleegyezett valamibe, ami meg sem történt. A tenant regisztrációnál fordítva —
ott a kettő **egy tranzakcióban** van, mert egy tenant, ami létezik az őt létrehozó
elfogadás nélkül, olyan hiányosság, amit később semmi nem venne észre.

### 10.4 Egy verziót hatályba lépés után nem írunk felül

A hozzájárulás egy **szövegre** vonatkozik. A szöveg átírása egy rögzített elfogadás
alatt a bizonyítékot állítássá változtatja. Ezért: új szöveg = **új sor**, és az
`effective_from` dönti el, melyik van hatályban. Jövőbeli dátum = meghirdetett
verzió (három állapot: hatályos / meghirdetve / lejárt).

Ebből következik a **verzió-verseny** kezelése is: az űrlap visszaküldi, mely
dokumentum-id-kat mutatta, és ha időközben új lépett hatályba, a beküldést
**elutasítjuk**. Sem az újra nem rögzíthetjük (nem látta), sem a régire (már nem az
van hatályban).

### 10.5 Újra-elfogadás

Az `EnsureLegalConsent` middleware adja a verziózás értelmét: nélküle az új szöveg
senkire nem vonatkozna, akinek már van fiókja. Blokkoló képernyő, nem elzárható
sáv — utóbbi használhatóan hagyná a terméket olyasvalaki számára, aki nem fogadta el
a feltételeket, amik alatt használja.

Kivételek, amik kapuvá és nem csapdává teszik: **maga a dokumentum**, az elfogadó
űrlap, a kijelentkezés és a bejelentkezés. Superadmintól **soha nem kérünk**
elfogadást (a slot4u nem szerződik önmagával), vendégtől sem — őt a foglalás
pontján kérdezzük.

### 10.6 Megőrzés — miért nem söpri a retention

A §7.1 az `audit_logs.ip_address`-t 90 nap után nullázza. A `legal_consents.ip_address`
**megmarad**. A különbség az, hogy mire való: az audit soron az IP telemetria egy
amúgy is rögzített művelethez, itt viszont **maga a bizonyíték része** — az elfogadás
körülményei nélkül a rekord állítás, nem bizonyíték.

`user_agent` oszlop **nincs**: második azonosító lenne, jóval kisebb bizonyító
erővel, mint az időbélyeg és a verzió — az adatminimalizálás pont abban a táblában
nem opcionális, ami a megfelelés igazolására létezik.

### 10.7 A 15. cikkes exportban

A hozzájárulások **benne vannak** az ügyfél adatmásolatában (§2): az az adat, amivel
az adatkezelést igazoljuk, ugyanúgy az érintettről szól. A vendégként adott
elfogadások email-egyezéssel is bekerülnek, tehát aki előbb fiók nélkül foglalt és
később regisztrált, a **teljes** előzményét kapja, nem a rendezettebbnek látszó felét.

⚠️ **A dokumentumok SZÖVEGE jogászra vár.** A seeder látható helyőrzőket tesz ki,
amik ezt ki is mondják magukról: egy hihetően hangzó vázlat rosszabb lenne, mert
senki nem venné észre, hogy cserélni kell.

### 10.8 Számlázási cím (SLO-168)

A foglaláson tárolt számlázási cím (`bookings.billing_*`) **személyes adat**, és ugyanúgy
kezelendő: benne van a **15. cikkes exportban**, és a **törlés mind a hat oszlopot
nullázza**.

⚠️ **A kiállított számla a saját másolatát megtartja** — ez a §3.3 két tudatos kivételének
egyike (Szt. 169. §, 8 év), nem mulasztás. A `bookings` sor viszont nem bizonylat, ezért
onnan megy.

⚠️ **Amit ez tanított a söprésről:** a `PersonalDataSweep` az ÉLŐ sémán keres, de csak azt
találja meg, amit egy fixture beleírt. A hat új oszlop úgy landolt, hogy **minden privacy
teszt zöld maradt** — mert egyetlen fixture sem írt bele számlázási címet. Ez hamis zöld
volt, nem garancia. A javítás ezért **a fixture-ben** van (`erasureFixture()`), nem egy
külön tesztben: így minden meglévő söprés-állítás magától fedi az új oszlopokat, és a
következő PII-oszlop is aznap bukik, amikor beérkezik.

## 11. Cookie consent (SLO-165) és a mérés (SLO-172)

### 11.1 Mit kapcsol ki ma

Az `analytics` kategória a **slot4u saját GA4 tagjét** kapcsolja a marketing-oldalon
(SLO-172). A `marketing` kategória ma még semmit — a tenant-oldali Meta Pixel a
SLO-56-tal érkezik.

A `necessary` sütik (Laravel session + XSRF) az ePrivacy szerint hozzájárulás
nélkül is mehetnek, ezért nem is választhatók.

### 11.1.1 A kapu a szerveren van, nem a böngészőben

```php
// app/Support/Analytics/PlatformAnalytics.php
if (! CookieConsent::fromRequest($request)->allows('analytics')) { /* nincs tag */ }
```

A döntés **három** feltétel egyike, és mindhármat a szerver hozza meg, mielőtt
egyetlen bájt kimenne:

1. van beállított mérőazonosító (`ANALYTICS_GA4_MEASUREMENT_ID`, prod-only),
2. a kérés a **központi domainre** jött,
3. a látogató megadta az `analytics` hozzájárulást.

Amíg ezek nem állnak, a `gtag.js` **nem kerül bele a HTML-be** — nem „betölt, de
nem mér", hanem nincs ott. A CSP-t ugyanaz az objektum tágítja
(`ContentSecurityPolicy::$analytics`), tehát elutasítás esetén a policy sem
engedné meg a googletagmanager.com-ot. Két független zár, ugyanarra a döntésre.

⚠️ **A döntés megváltoztatása teljes újratöltést vált ki**
(`CookieConsent.tsx` → `window.location.reload()`). Ez nem kényelmi kérdés: a tag
a dokumentum `<head>`-jében ül, egy Inertia-navigáció pedig csak az oldal-komponenst
cseréli. Újratöltés nélkül a **visszavonás** nem érne semmit — a már betöltött tag
a munkamenet végéig futna tovább.

### 11.1.2 ⚠️ A tenant-aldoménen a platform SOHA nem mér

`{slug}.slot4u.hu`-n a **tenant az adatkezelő**, a slot4u az adatfeldolgozó (§2).
Egy slot4u tulajdonú GA4 property, ami ezt a forgalmat gyűjti, azt jelentené, hogy
a platform a **más nevében kezelt** adatból csinál magának terméket — az
adatfeldolgozói szerep megsértése, függetlenül attól, hogy a látogató mit
kattintott a banneren. Ezt kódban a `PlatformAnalytics::isCentralHost()` zárja, és
külön teszt őrzi (`tests/Feature/Analytics/PlatformAnalyticsTest.php`).

A tenant **saját** mérőkódja (SLO-56) más lapra tartozik: ott a tenant a saját
adatkezelői döntését hozza meg, a slot4u csak a technikai kiszolgáló.

### 11.2 Süti, nem localStorage — és nem DB sor

**Süti**, mert a szervernek **az első bájt előtt** tudnia kell: egy böngészőben
eldöntött láthatóság minden szerver-renderelt oldalon felvillantja a bannert, egy
böngészőben kapuzott script pedig már letöltődött, mire kapuzzuk.

**Nem adatbázis-sor**, mert a döntés egy **böngészőé**, nem egy személyé — egy
névtelen látogatót azonosítani ahhoz, hogy rögzítsük, nem kér mérést, önmagában
adatvédelmi költség. Ezért nem is kerül a `legal_consents` táblába (§10): az
dokumentum-elfogadásokról szól, a süti-preferencia nem az.

⚠️ **A süti NEM titkosított** (`bootstrap/app.php` `encryptCookies(except:)`).
Preferencia, nem jogosultság: nem hitelesít semmit, a módosítása csak azt
változtatja, mit kap ugyanaz a böngésző. Cserébe egy jövőbeli third-party tag
szinkron módon el tudja olvasni, és a látogató **meg tudja nézni** azt az
adatvédelmi kontrollt, amit kapott — ez a helyes tartás olyasminél, aminek
bizalomkeltőnek kell lennie.

### 11.3 Verziózva, mint egy jogi dokumentum

A `config/consent.php` `version` kulcsa: ha a kategóriák vagy a süti-tájékoztató
változik, a régi verziót megnevező döntés **nem döntés** — a banner újra kérdez.
Ugyanaz a szabály, mint a §10-ben: egy **másik** opciókészletről hozott döntés nem
döntés **erről**.

### 11.4 Amit a tervezés kimondottan nem enged

* **A hallgatás soha nem igen.** Amíg nincs döntés, minden kategória tiltott.
* **Az elutasítás egy kattintás**, ugyanúgy, mint az elfogadás. Két szint mélyre
  temetett „elutasítom" az a minta, amitől a banner hozzájárulásként értéktelen.
* **Az elutasítás ugyanolyan tartós, mint az elfogadás** — a rövid életű „nem" a
  következő látogatáskor újra kérdezne, ami rászoktatja az embereket az
  elfogadás-gombra.
* **A `necessary` nem választható**, csak felsorolt: a session süti az, amitől a
  foglalási űrlap működik, egy kapcsoló mellette azt sugallná, hogy visszautasítható.
* **A süti kategórialistája nem parancs:** egy ismeretlen kategóriát tartalmazó
  süti semmit nem enged — a lista az appé, nem a sütié.
