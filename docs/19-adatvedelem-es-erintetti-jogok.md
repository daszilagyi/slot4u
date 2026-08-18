# 19 — Adatvédelem: érintetti jogok, adatexport és törlés

> **Státusz:** az SLO-159 (SLO-48 1/3) szállította az érintetti jogok technikai
> oldalát. A **megőrzési idők és az automatikus takarítás** (archivált tenant 90
> nap, log-retention) az **SLO-160**-é, a **verziókövetett hozzájárulás és a
> cookie consent** az **SLO-161**-é — mindkettő ebbe a dokumentumba fog beépülni.
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

## 6. Ami még hátravan

| Mi | Hol |
|---|---|
| Archivált tenant 90 nap → anonimizálás, log-retention, tenant adatexport kilépéskor | **SLO-160** |
| Verziókövetett ÁSZF/adatkezelési elfogadás, cookie consent | **SLO-161** |
| ÁSZF + adatkezelési tájékoztató szövege | **jogász** |
