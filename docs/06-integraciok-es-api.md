# 06 — Integrációk és API

## API filozófia

Az API elsődleges célja külső rendszerek integrálása — de NEM az MVP része. Sorrend:

1. **MVP:** csak Internal API (Inertia) + a beépített integrációk (fizetés, számlázás — M6). Publikus API endpoint nem létezik.
2. **Phase 2:** Public API (`feature_api`) API kulcsos hitelesítéssel.
3. **Phase 3:** Partner API OAuth2-vel, kimenő webhookokkal.

**Fejlesztői következmény már az MVP-ben:** minden üzleti logika Action/Service rétegben él, controller-függetlenül. Így a Public API később csak egy új belépési pont (route + transformer), nem újraírás. Ez a docs/01 konvenció — itt válik kötelezővé.

## API típusok

| Típus    | Fogyasztó                            | Auth                      | Fázis   |
| -------- | ------------------------------------ | ------------------------- | ------- |
| Internal | saját Inertia frontend               | session (web guard)       | MVP     |
| Public   | tenant saját fejlesztései, weboldala | API key                   | Phase 2 |
| Partner  | CRM, ERP, marketing rendszerek       | OAuth2 client credentials | Phase 3 |

## Public API (Phase 2) — specifikáció

### Alapok

- Verziózás: `https://api.slot4u.hu/v1/...` VAGY tenant-domainen `/api/v1/...` — **döntés implementáció előtt** (javaslat: tenant-domain alapú, mert a kulcs úgyis tenant-scoped és a CORS/branding egyszerűbb).
- Formátum: JSON; hibák egységesen RFC 7807 szerint (`type`, `title`, `status`, `detail`, `errors{}`).
- Lapozás: cursor-alapú (`?cursor=...&limit=`), max 100 elem.
- Minden időpont ISO 8601 UTC-ben, a válasz tartalmazza a tenant timezone-t.
- Dokumentáció: OpenAPI 3 spec a kódból generálva (pl. scramble/scribe), publikus docs oldal.

### Endpointok (v1 minimum)

```
GET    /v1/services                     szolgáltatáslista (publikus adatok)
GET    /v1/availability                 szabad slotok (service, date range, staff szűrők)
GET    /v1/events                       meghirdetett események
POST   /v1/bookings                     foglalás létrehozás (Idempotency-Key header KÖTELEZŐ)
GET    /v1/bookings/{code}              foglalás lekérdezés
DELETE /v1/bookings/{code}              lemondás (tenant lemondási szabálya érvényes)
GET    /v1/customers/{id}               saját ügyféladat (scope-tól függően)
POST   /v1/webhooks  /  GET /v1/webhooks  kimenő webhook feliratkozások kezelése
```

### Hitelesítés — API key

- `api_keys` tábla: `id, tenant_id, name, key_hash, prefix, scopes(json), last_used_at, expires_at, revoked_at`
- A kulcs csak létrehozáskor látszik egyszer (hash tárolás, `sk_live_xxx` prefix az azonosításhoz)
- Scope-ok: `bookings:read`, `bookings:write`, `availability:read`, `customers:read`, `webhooks:manage` — a tenant admin a kulcs létrehozásakor választ
- Kulcskezelő UI a tenant adminban (billing/settings jog), létrehozás/visszavonás auditolva
- OAuth2 (Phase 3): Laravel Passport client credentials flow, partner-onboarding folyamattal

### Rate limiting (base default + superadmin override)

Nincs csomag-tiering (egyetlen `base` plan). Egy nagyvonalú default napi/burst limit a `feature_api`-t használó tenantokra, amit a superadmin tenant-onként felülírhat (ugyanaz a platform-default + tenant-override minta, mint a jutalék-beállításnál — docs/10 §2.1).

| Szint | Napi limit | Burst |
|---|---|---|
| base default | 10 000 kérés/nap | 120/perc |
| fair-use soft limit | 100 000/nap | 600/perc |

- Redis-alapú számláló kulcsonként ÉS tenant-onként; headerek: `X-RateLimit-Limit/Remaining/Reset`
- Limit felett `429` + `Retry-After`; ismétlődő abúzus → kulcs automatikus felfüggesztés + admin riasztás
- A billing/API oldalon látszódjon a kihasználtság

### Tenant izoláció (kritikus!)

- Minden API kérés a kulcsból feloldott tenant scope-ban fut — a meglévő `BelongsToTenant` global scope érvényes, kivétel nincs
- Cross-tenant ID-próbálkozás: `404` (nem `403` — ne szivárogtassunk létezés-információt)
- Az M8 izolációs teszt-csomag (SLO-47) kötelezően kiterjed minden API endpointra

### Idempotencia és konkurencia

- `POST /v1/bookings`: `Idempotency-Key` header — ismételt kérés ugyanazzal a kulccsal ugyanazt a választ adja (24h tárolás), dupla foglalás kizárva
- Az ütközésvédelem ugyanaz a CreateBooking action, mint a weben (SLO-24) — az API nem kerülheti meg

## Kimenő webhookok (Phase 2/3)

- Feliratkozható eventek: `booking.created`, `booking.confirmed`, `booking.canceled`, `booking.completed`, `payment.succeeded`, `payment.failed`, `quote.accepted`
- Aláírás: HMAC-SHA256 a tenant webhook secretjével (`X-Slot4u-Signature`)
- Kézbesítés: queue-ból, exponenciális retry (5x), utána dead-letter + admin riasztás
- Napló: `webhook_logs` (lásd docs/02) — payload, processed státusz, válaszkód

## Számlázás — provider-absztrakció és a Billingo (SLO-133, SLO-167)

A számlázó **cserélhető**, és a választás a **tenanté**: ő szerződik a
szolgáltatóval, a saját fiókjában keletkeznek a számlái, és a saját kulcsával
hívjuk. A slot4u a gépezetet adja, nem a szolgáltatót.

```
InvoiceIssuer (interfész)          ← minden fölötte lévő réteg provider-agnosztikus
├── SandboxInvoiceIssuer           ← beépített teszt-kiállító (nincs jogi ereje)
├── BillingoInvoiceIssuer          ← SLO-167, az első valódi
└── (Számlázz.hu)                  ← SLO-134, PARKOLVA
```

A választás a tenant titkosított `invoicing` beállításában él
(`TenantInvoicingSettings::$provider`); a `config/invoicing.php` **csak
fallback** annak a tenantnak, aki még nem választott. Korábban ez fordítva volt —
egy érték az egész platformra —, ami két, különböző szolgáltatón számlázó
tenantot soha nem tudott volna kiszolgálni.

⚠️ **A `szamlazzhu` enum-eset megmarad adapter nélkül**, mert egy régi
`invoices` sornak meg kell tudnia nevezni, ki állította ki. De **nem szabad
elérhetőnek látszania**: a `selectable()` kihagyja a beállítás-űrlapból, a
manager pedig **név szerint utasítja el**. A csendes visszaesés a sandboxra
jogi erő nélküli bizonylatot állítana ki és sikert jelentene — rosszabb minden
hibánál.

### Amit a Billingo élő mérése hozott (2026-08-21)

Ezek nem a specifikációból derültek ki, hanem a demo fiókon végigmért
partner → számla → PDF → sztornó láncból:

| Tény | Következmény a kódra |
|---|---|
| ⚠️ **A Billingo egész forintban számol**, mi fillérben (`docs/01` §6) | `unit_price = amount_minor / 100`. Kimaradva **százszoros** összegű számla — külön teszt őrzi, mert egy azonos hibát tükröző fake nem venné észre |
| **A `partner_id` kötelező**, beágyazott vevő nincs | a kiállítás két hívás: partner, majd dokumentum |
| ⚠️ **A partner nem kereshető email alapján** (a `?query=` névre illeszt) | a leképezést **nálunk** tároljuk: `invoicing_partners` (tenant + provider + email → partner id). Névre keresni ütközne és a vevőnév javításakor elhasadna |
| **A sztornó ÚJ dokumentum** saját számlaszámmal, negatív végösszeggel | nem az eredetit módosítjuk; a sztornó száma és PDF-je külön oszlopban |
| A `bank_account_id` **opcionális** | bankszámla nélkül is lehet számlázni; csak átutalásnál kerül a számlára |
| A NAV Online Számla a **Billingo fiók** beállítása | a slot4u nem küld a NAV felé, és nem is tud: `no_online_szamla_settings` esetén a Billingo maga nem továbbít |

### Nyugta alapból, számla kérésre (SLO-168)

⚠️ **Ezt egy élő próba derítette ki, nem a specifikáció.** Az első valódi hívás 422-vel
bukott: a Billingo a partnerhez **teljes címet** követel. És ez nem szolgáltatói
sajátosság — az **Áfa tv. 169. § e)** szerint a vevő **neve ÉS címe** kötelező adata a
**számlának**. A slot4u viszont sehol nem gyűjtött címet, tehát **semmilyen
szolgáltatóval** nem tudott volna szabályos számlát kiállítani.

**Döntés (Daniel, 2026-08-21):** alapértelmezésben **nyugta**, kérésre **számla**.

| | Nyugta (`POST /documents/receipt`) | Számla (`POST /documents`) |
|---|---|---|
| Mikor | **alapértelmezés** | ha a vevő kérte ÉS megadta a címét |
| Partner | **nincs** — név és email sima mező | kötelező (`partner_id`), címmel |
| Cím | **nem kell** | kötelező (Áfa tv. 169. § e) |
| Tömb | külön **nyugtatömb** | számlatömb |

Magánszemélynek kártyás fizetésnél a nyugta jogilag elegendő, és a foglalási űrlapot sem
terheli meg három mezővel mindenkinél. A cím így **csak attól gyűlik, akinek tényleg kell**
— ez a `docs/19` adatminimalizálási elve, nem kényelmi döntés.

⚠️ **A hiányos cím nem bukik el, hanem nyugtát ad.** Aki bepipálta a „számlát kérek"-et, de
félig töltötte ki a címet, azt **az űrlap utasítja el** — de ha valamiért mégis idáig jut
(admin által rögzített foglalás, importált adat), a bizonylat inkább nyugta lesz, mint
semmi: az ügyfél már fizetett, és egy jogilag érvényes bizonylat jobb, mint egy elbukott
tranzakció. A `bookings.wants_invoice` megőrzi, hogy mit kért, tehát az admin látja.

⚠️ **A PDF nem készül el a dokumentummal egyszerre.** A Billingo aszinkron rendereli, és
egy túl korán érkező letöltésre **HTTP 202**-t ad, 59 bájtnyi JSON-nal:
`{"error":{"message":"Document PDF has not generated yet."}}`.

**A 202 egy 2xx**, tehát minden „sikeres volt?" ellenőrzés igent mond — így ez a hiba
egészen egy éles próbáig eljutott: az adapter ezt a JSON-t mentette el az ügyfél
számlájaként, a sor `issued` lett, és semmi nem látszott volna rosszul, amíg valaki rá nem
kattint a letöltésre. A demo fiókon mérve: 0 és 1 másodpercnél még nem kész, 2-nél igen.

A `downloadDocument()` ezért **két feltételt** néz, nem egyet: a státusz ne 202 legyen,
**és** a bájtok tényleg PDF-fel kezdődjenek. Egy „siker", ami nem dokumentum, pont az a
hibamód, ami ellen ez a metódus létezik. Hat próbálkozás, fél másodperces szünetekkel; utána
kivétel, amit a queue-olt job saját backoffja újrapróbál.

**A számlázási cím a `bookings`-en él, nem a `users`-en.** Tranzakciós adat: az a cím,
amire a bizonylat kiállt, és nem változhat visszamenőleg attól, hogy az ügyfél később
elköltözött. Egyben a törlés és az export is egy helyen söpri (`docs/19`).

**Kulcs-kezelés:** a tenant API kulcsa a titkosított `invoicing` oszlopban él, és
**soha nem megy vissza a böngészőnek** — a beállító képernyő azt tudja meg, hogy
*van* kulcs, nem azt, hogy *mi*. Üresen hagyott mező = „hagyd a meglévőt", mert a
form nem is kaphatta meg, hogy visszaküldje.

**Számlatömb és bankszámla legördülőből** jön (`GET /document-blocks`,
`GET /bank-accounts`), nem kézzel beírt azonosítóként: egy elgépelt id csak egy
valódi számlánál derülne ki, amikor az ügyfél már fizetett.

**Szerződés:** OpenAPI 3.0.14, `https://api.billingo.hu/v3`, `X-API-KEY` fejléc.

## Beépített integrációk naplózása (már MVP/M6!)

Minden külső hívás (Barion/Stripe, Számlázz.hu, később calendar/marketing) az `integration_logs` táblába kerül (provider, operation, request/response, status — lásd docs/02). Szenzitív adat (kártya, API kulcs) a logban maszkolva. Retention: 90 nap.

## PM nézet — ütemezés és kockázatok

| Mi | Mikor | Linear |
|---|---|---|
| Action/Service réteg fegyelem (API-ready kód) | MVP, folyamatos | minden issue DoD része |
| integration_logs + webhook fogadás (fizetés) | M6 | SLO-39/40/41 része |
| Public API v1 + API kulcsok + rate limit | Phase 2 | új milestone, ~5-7 issue |
| Kimenő webhookok | Phase 2 | 1-2 issue |
| OAuth2 Partner API + partner onboarding | Phase 3 | igény szerint |

**Kockázatok:** (1) API-val a foglalási spam/abúzus felülete nő — rate limit + bot-védelem kell a publikus availability endpointra. (2) A v1 szerződés kiadás után nehezen törhető — az endpoint-lista és hibaformátum legyen review-zva kiadás előtt. (3) Idempotencia nélkül a partner-integrációk dupla foglalásokat termelnek — ezért kötelező header, nem opció.

**Definition of Done (Public API megjelenéskor):** OpenAPI docs publikus; izolációs + rate limit + idempotencia tesztek CI-ban; kulcskezelő UI auditolva; changelog/verziópolitika dokumentálva.
