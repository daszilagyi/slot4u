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
