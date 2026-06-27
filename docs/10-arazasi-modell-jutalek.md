# 10 — Árazási modell: forgalom-alapú jutalék (havi számlázás) — IMPLEMENTÁCIÓS SPEC

> **Státusz:** elfogadott irány, implementációra kész spec. Ez a dokumentum az árazási modell **igazság-forrása** (docs konvenció). Ahol ellentmond a 02/03/05-nek, ott ez a dokumentum nyer, és a 02/03/05-öt ugyanabban a PR-sorozatban frissíteni kell (lásd §16).
>
> **Verzió:** v2 (2026-06-22). A v1 (online-fizetés-alapú application-fee modell) **visszavonva** — Daniel döntése alapján a jutalék mostantól a **foglalási forgalomból** (lista­ár × jutalékköteles foglalások) számolódik, fizetési csatornától függetlenül, és **havi jutalékszámlán** kerül beszedésre. A v1↔v2 különbség összefoglalója: §0.
>
> **Készítette:** PM-szintű előkészítés. **Implementálja:** Claude, review+merge: Daniel.
>
> **Döntésnapló (rögzített, nem nyitjuk újra implementáció közben):**
> - A korábbi háromlépcsős fix előfizetés (Alap/Közepes/Max) **megszűnik**. Egyetlen ingyenes base tier van; a monetizáció a forgalom-alapú jutalék.
> - A jutalék alapja a **jutalékköteles foglalások listaárának** havi összege (a tenant „forgalma"), **nem** a platformon átfolyó pénz. A slot4u **nem** ül be a pénzáramlásba — a foglalás ellenértéke közvetlenül a tenanthoz fut (készpénz, banki utalás, vagy a tenant saját fizetési integrációja).
> - A jutalékot a slot4u **havi jutalékszámlán** számlázza ki a tenantnak (a slot4u saját, ÁFA-s SaaS-bevétele). Nemfizetés → fizetési emlékeztetők → tenant felfüggesztés.
> - Minden árazási paraméter **konfigurálható** (superadmin platform-default + tenant-szintű felülírás); a kódban csak default érték.

---

## 0. Mi változott a v1-hez képest (gyors összefoglaló)

| Szempont | v1 (visszavonva) | **v2 (ez a doc)** |
|---|---|---|
| Jutalék alapja | csak a platformon átfolyó **online fizetés** | a **jutalékköteles foglalások listaára** (bármilyen fizetési mód) |
| Beszedés | fizetési provider **payee-split / application fee** | havi **jutalékszámla**, a tenant **utalja** |
| Pénzáramlás | provider osztja meg, slot4u sosem fogadja a pénzt | slot4u **nem** kezeli a foglalás ellenértékét egyáltalán |
| Jogi kockázat | PSD2/MNB engedélyköteles-e a split → **blokkoló jogi spike** | **nincs payment-facilitator kérdés** — slot4u csak a saját SaaS-díját számlázza (lásd §4) |
| Ingyenes küszöb | 20 000 Ft online forgalom | **10 000 Ft** foglalási forgalom |
| 1,5%-os ráta kiváltója | csak a számlázás add-on | **bármelyik integráció** (online fizetés **vagy** számlázás) aktív |
| Lemondás/no-show | nincs alap → nincs jutalék | **24 órán túli lemondás** jutalékmentes; no-show és késői lemondás **számít** (§3) |
| Számítás idő | fizetésenként, valós időben | **havi**, a period zárásakor (a dashboard valós idejű becslést mutat) |

---

## 1. Vezetői összefoglaló

A slot4u monetizációja a **„Pay-as-you-grow"** modellre vált:

1. **A foglalási motor mindenkinek ingyenes.** Nincs belépő havidíj, nincs csomagválasztás. A tenant regisztrál, felviszi a törzsadatait, az ügyfelei foglalhatnak — fizetési kötelezettség nélkül. Ez a verhetetlen onboarding-üzenet: „0 Ft, fizetsz, ha már forgalmad van."
2. **A bevétel = forgalom-alapú jutalék.** A rendszer havonta összegzi a tenant **jutalékköteles foglalásainak listaárát** (ez a „forgalom"), és erre számol jutalékot. A jutalék a slot4u saját, ÁFA-s SaaS-bevétele, amit havi számlán szed be.
3. **Ingyenes küszöb havonta:** egy konfigurálható havi forgalomig (default **10 000 Ft**) a jutalék **0%**. E felett **csak a küszöb feletti (marginális) részre** számolódik jutalék.
4. **Jutalékráta:** default **1,0%** (100 bps). Ha a tenantnál **bármelyik rátaemelő integráció** aktív — online kártyás fizetés (Barion/Stripe) **vagy** számlázás (Számlázz.hu/Billingo) —, a ráta **1,5%** (150 bps).
5. **Havi jutalékplafon:** konfigurálható felső korlát (default **50 000 Ft/hó/tenant**). A plafon elérése után az adott hónapban nincs több jutalék.
6. **Lemondás/no-show:** a foglalás csak akkor vehető ki a forgalomból jutalékmentesen, ha **a kezdés előtt több mint 24 órával** lemondták. A no-show és a 24 órán belüli lemondás **beleszámít** a forgalomba (§3).

**Miért működik üzletileg:**

- *Súrlódásmentes belépés.* A tenant kockázatmentesen kezd: amíg nincs érdemi forgalma (10 000 Ft/hó alatt), nem fizet semmit.
- *Igazságos skálázódás.* A díj a tenant valódi aktivitásával nő, de a havi plafon kiszámíthatóvá teszi a maximális költséget.
- *Egyszerű jogi helyzet.* Mivel a slot4u **nem** kezeli a foglalás ellenértékét, nincs pénzforgalmi közvetítői (payment facilitator) kérdés (§4). A slot4u csak a saját szolgáltatási díját számlázza — ez normál B2B SaaS-számlázás.

**Vállalt modell-tulajdonságok (tudatos kompromisszumok):**

- *Listaár ≠ ténylegesen befolyt pénz.* A jutalék alapja a foglalás **listaára**, nem a ténylegesen kifizetett összeg. A tenant által adott egyedi kedvezmény, borravaló stb. nem jelenik meg. Mivel a tenant a saját nyilvános árait állítja (az ügyfelek látják), természetes nyomás van a valós árazásra.
- *No-show díjazható.* Daniel döntése szerint a no-show és a késői lemondás jutalékköteles marad — ez összhangban van azzal, hogy a tenant ilyenkor jellemzően maga is lemondási díjat számít fel.

---

## 2. A modell pontos matematikai definíciója

Minden összeg **integer minor unit** (`*_minor`, fillér), minden ráta **integer basis point** (`*_bps`, 1% = 100 bps) — **float TILOS** (docs/01 §6).

### 2.1 Paraméterek (feloldási sorrend: tenant-override → platform-default)

| Paraméter | Kulcs | Default | Megjegyzés |
|---|---|---|---|
| Ingyenes havi küszöb | `free_threshold_minor` | `1_000_000` (=10 000 Ft) | havi forgalom, ami alatt 0% |
| Alap jutalékráta | `rate_bps` | `100` (=1,0%) | rátaemelő integráció nélkül |
| Emelt jutalékráta | `rate_with_integration_bps` | `150` (=1,5%) | ha **bármelyik** rátaemelő integráció aktív |
| Havi jutalékplafon | `monthly_cap_minor` | `5_000_000` (=50 000 Ft) | `null` = nincs plafon |
| Pénznem | `currency` | `HUF` | tenantonként egységes (multi-currency: §15) |

**Rátaemelő integrációk halmaza** (`rate_affecting_features`): `feature_online_payment`, `feature_invoicing`. Ha a foglalás jutalékkötelessé válásának pillanatában **legalább az egyik** aktív → az adott foglalás `150` bps-szel számol, különben `100` bps-szel (§2.4 a hónap közbeni váltásról).

### 2.2 Elszámolási időszak (period)

A `period` kulcs = a **tenant timezone** szerinti naptári hónap, `YYYY-MM` formátumban (nem UTC-hónap, mert ez számlázási határ). A küszöb és a plafon hónap elején nullázódik. DST-átállás és hónaphatár tesztelendő (§12).

**Egy foglalás period-hozzárendelése:** a foglalás `starts_at` mezőjének hónapja (tenant-tz) szerint. Időpont nélküli foglalási módnál (lásd docs/04) a foglalás **jutalékkötelessé válásának** dátuma szerint. A forgalom tehát a szolgáltatás tervezett teljesítésének hónapjához tartozik, nem a foglalás létrehozásának hónapjához.

### 2.3 A havi jutalék kiszámítása (marginális + plafon)

A period jutaléka a period **összes jutalékköteles foglalásából** számolódik. Legyen a jutalékköteles foglalások listája (§3 szabályai szerint), **a jutalékkötelessé válás időrendjében** rendezve, mindegyik `(amount_minor, rate_bps)` párral, ahol a `rate_bps` a §2.1/§2.4 szerinti ráta az adott foglalásra.

```
F   = free_threshold_minor          // küszöb
K   = monthly_cap_minor             // plafon (lehet null)

cum        = 0                      // halmozott forgalom (minor)
commission = 0                      // halmozott jutalék (minor)

for each billable booking b in chronological order:
    amt  = b.amount_minor
    // a küszöb FELETTI, jutalékköteles rész CSAK ebből a foglalásból:
    base = max(0, (cum + amt) - max(F, cum))
    // egész osztás, lefelé kerekítve (a tenant javára), float nélkül:
    commission += intdiv(base * b.rate_bps, 10_000)
    cum  += amt

// plafon érvényesítése a period összjutalékára:
commission = (K === null) ? commission : min(commission, K)
```

**A modell tulajdonságai (tesztelendő, §12):**

- A küszöb alatti összforgalomnál a jutalék **0**.
- A küszöböt átlépő forgalomnál csak a **feletti** rész jutalékköteles (marginális).
- A küszöböt időrendben a **korábbi** forgalom „tölti fel" — így hónap közbeni rátaváltásnál (§2.4) az emelt ráta csak a később keletkezett, küszöb feletti forgalmat terheli (nem retroaktív).
- A plafon elérése után a period jutaléka nem nő tovább.
- Kerekítés mindig lefelé, determinisztikus.
- `F = 0` → első fillértől jutalék; `K = null` → korlátlan; mindkettő definiált eset.

**Worked example (default paraméterek, egységes 1,0% ráta):**

| Havi forgalom | Küszöb feletti rész | Nyers jutalék (1%) | Plafonozott jutalék |
|---|---|---|---|
| 8 000 Ft | 0 | 0 Ft | 0 Ft |
| 10 000 Ft | 0 | 0 Ft | 0 Ft |
| 30 000 Ft | 20 000 Ft | 200 Ft | 200 Ft |
| 1 000 000 Ft | 990 000 Ft | 9 900 Ft | 9 900 Ft |
| 6 000 000 Ft | 5 990 000 Ft | 59 900 Ft | **50 000 Ft** (plafon) |

1,5%-os rátánál (rátaemelő integráció aktív): pl. 30 000 Ft forgalomnál a jutalék 1,5% × 20 000 = **300 Ft**; a plafon ekkor ≈ 3 343 333 Ft forgalomnál áll be.

### 2.4 Ráta hónap közbeni változása (integráció be-/kikapcsolása)

A ráta foglalásonként, a foglalás **jutalékkötelessé válásának pillanatában** dől el (snapshot a `booking_commission_items.rate_bps`-be, §5.3). Ha a tenant hónap közben kapcsol be egy rátaemelő integrációt, az **csak az aktiválás után** jutalékkötelessé váló foglalásokat érinti — **nem retroaktív** (a §2.3 időrendi algoritmus ezt automatikusan kezeli). Tesztelendő: §12/14.

---

## 3. Mi számít forgalomnak — jutalékköteles foglalás szabályai

A „forgalom" a period **jutalékköteles** foglalásainak `bookings.price_minor` szerinti összege. Egy foglalás akkor jutalékköteles, ha materializálódott vagy a tenant díjat számíthatott fel érte.

### 3.1 Jutalékköteles állapotok (`BookingStatus`, docs/02 §Foglalások)

| Foglalás állapota | Jutalékköteles? | Indok |
|---|---|---|
| `confirmed` | **Igen** | véglegesített foglalás |
| `completed` | **Igen** | teljesült |
| `no_show` | **Igen** | Daniel döntése: a meg nem jelenés díjazható |
| `canceled`, ha `canceled_at` **> `starts_at − 24h`** | **Igen** | 24 órán belüli (késői) lemondás |
| `canceled`, ha `canceled_at` **≤ `starts_at − 24h`** | **Nem** | 24 órán túli lemondás → jutalékmentes kivét |
| `requested`, `approved`, `pending_payment` | **Nem** | még nem materializálódott; ha sosem `confirmed`, sosem számláz |
| `rejected` | **Nem** | a tenant elutasította |

> **Időpont nélküli foglalási mód** (nincs `starts_at`): a 24 órás szabály nem értelmezhető a kezdéshez képest. Ilyenkor a lemondás akkor jutalékmentes, ha a foglalás **jutalékkötelessé válása óta** még nem telt el 24 óra, vagy admin által manuálisan stornózható. Pontos szabály a módspecifikus implementációnál (docs/04), itt a default: a `confirmed`-be lépéstől számított 24 órás „grace" alatti lemondás jutalékmentes.

### 3.2 Ár-forrás

- A foglalás `price_minor` mezője (a foglaláskor rögzített **snapshot**, docs/02) az alap. A szolgáltatás listaárának (`services.price_minor`) későbbi módosítása **nem** hat vissza a már rögzített foglalásokra.
- `price_minor = 0` (ingyenes szolgáltatás) → 0 forgalmi hozzájárulás.
- `quote_requests` (ajánlatkérés) önmagában **nem** forgalom; csak ha elfogadott árral `confirmed` foglalássá konvertál — ekkor a `bookings.price_minor` (a kialkudott ár) számít.
- Várólista (`waitlist_entries`) nem forgalom, amíg nem lesz belőle `confirmed` booking.

### 3.3 Állapotváltás visszahatása a forgalomra

A forgalom egy foglalás állapotváltozásakor frissül (§6, event-vezérelt):

- `confirmed` / `completed` / `no_show` → a foglalás **bekerül** a period ledgerébe (vagy ott marad).
- 24 órán **túli** lemondás → a foglalás **kikerül** a ledgerből (`removed`), forgalom csökken, period újraszámol.
- 24 órán **belüli** lemondás → **bennmarad** (késői lemondás díjazható).
- Ár utólagos módosítása admin által (ha engedélyezett) → a ledger-tétel `amount_minor` frissül, period újraszámol; **lezárt period-ot már nem** módosítunk (§8.2).

---

## 4. Pénzáramlás és jogi helyzet

**Architekturális alapszabály:**

> A slot4u **nem** kezeli a foglalás ellenértékét. A foglalás díját az ügyfél közvetlenül a tenantnak fizeti (készpénz a helyszínen, banki utalás, vagy a tenant saját — a slot4u-ban integrált — fizetési szolgáltatója). A slot4u kizárólag a **saját jutalékát** (szolgáltatási díját) számlázza a tenantnak, havonta.

**Következmény a v1-hez képest:** mivel a slot4u nem fogad és nem oszt újra ügyfélpénzt, **nincs payment-facilitator / pénzforgalmi közvetítői kérdés** — a v1 blokkoló jogi spike-ja (PSD2/MNB engedélykötelezettség a payee-splitre) **tárgytalan** a jutalék-beszedés szempontjából. A slot4u helyzete egy normál, használat-alapú (usage-based) B2B SaaS-szolgáltatóé.

**ÁFA / számlázás (slot4u oldal):** a jutalék a slot4u **adóköteles bevétele**, amelyről a slot4u **ÁFA-s számlát** állít ki a tenantnak. Döntés (§15.1): a `commission_minor` a **nettó** szolgáltatási díj, a havi jutalékszámla erre számít rá ÁFÁ-t (HU default 27%). A slot4u a saját számláit ugyanazzal a Számlázz.hu/Billingo integrációval is kiállíthatja (önálló, tenant-független számlázási csatorna).

> Megjegyzés: ha a tenant a saját ügyfeleitől online kártyás fizetést szed a slot4u-ban integrált Barion/Stripe szolgáltatón keresztül (M6 ügyfél-oldali fizetés), az **a tenant és az ügyfél** közti tranzakció — a slot4u abból semmit nem von le. Az integráció megléte csak a **jutalékrátát** emeli 1,5%-ra (§2.1). A két dolog (ügyfél-fizetés vs. jutalék-beszedés) **szigorúan külön** pénzáramlás.

---

## 5. Adatmodell (új és módosított táblák)

Konvenciók: tenant-tulajdonú táblán `tenant_id` (indexelt FK) + `BelongsToTenant` trait + global scope; pénz `*_minor` int + `currency`; ráta `*_bps` int; idő UTC; lefutott migrációt SOHA nem módosítunk (docs/01). Minden új modellre **kötelező tenant-izolációs Pest teszt** (§12).

### 5.1 Platform-szintű konfiguráció (tenant_id NÉLKÜL — superadmin)

```
commission_settings   id, free_threshold_minor, rate_bps, rate_with_integration_bps,
                       monthly_cap_minor(nullable), currency, effective_from,
                       created_by, created_at
                       -- verziózott: új sor = új konfiguráció, a régit nem írjuk felül
                       -- a "hatályos" beállítás: legnagyobb effective_from <= now()
```

> A verziózás azért fontos, mert egy múltbeli period jutalékát a **period idején hatályos** beállítással kell tudni rekonstruálni (audit, reconciliation).

### 5.2 Tenant-szintű felülírás (opcionális, superadmin állítja)

```
tenant_commission_overrides  tenant_id(PK,FK), free_threshold_minor(nullable),
                             rate_bps(nullable), rate_with_integration_bps(nullable),
                             monthly_cap_minor(nullable), note, overridden_by, updated_at
                             -- null mező = öröklés a hatályos commission_settings-ből
```

### 5.3 Jutalék-tétel ledger (forrás-igazság) — minden jutalékköteles foglalásra egy sor

```
booking_commission_items  id, tenant_id, booking_id(FK, unique), period(YYYY-MM, indexelt),
                          amount_minor,            -- a foglalás listaára (snapshot)
                          rate_bps,                -- a foglalásra érvényes ráta (snapshot, §2.4)
                          realized_at,             -- jutalékkötelessé válás időpontja (sorrendhez)
                          state(billable|removed), -- removed = 24h+ lemondás / elutasítás
                          settings_id(FK -> commission_settings), currency, created_at, updated_at
                          -- unique(booking_id): foglalásonként egy tétel; state váltással kezeljük a sorsát
```

### 5.4 Havi aggregátum (dashboard + számlázás cache-e)

```
tenant_billing_periods  id, tenant_id, period(YYYY-MM),
                        turnover_minor(default 0),      -- jutalékköteles forgalom összege
                        commission_minor(default 0),    -- a §2.3 szerint számolt jutalék
                        cap_reached(bool, default false),
                        status(open|invoiced|paid|overdue|void),
                        invoice_id(nullable, FK), recomputed_at, updated_at
                        -- unique(tenant_id, period)
                        -- DERIVÁLT cache: a booking_commission_items-ből újraszámolva
```

> **Számítási stratégia:** a `booking_commission_items` a forrás-igazság; a `tenant_billing_periods` egy belőle **újraszámolt cache**. Egy foglalás állapotváltásakor a period a ledgerből újraszámol (§6.2). Ez elkerüli a konkurens marginális/plafon-allokáció zárolási bonyodalmát: a jutalék mindig a teljes ledgerből, determinisztikusan számolódik. Egy period jellemzően max néhány ezer tétel — az újraszámítás olcsó és mindig korrekt.

### 5.5 Jutalékszámla (a slot4u → tenant havi számla)

```
commission_invoices   id, tenant_id, period(YYYY-MM, unique a tenanton belül),
                      turnover_minor, billable_base_minor, commission_net_minor,
                      vat_bps, vat_minor, total_gross_minor, currency,
                      status(draft|issued|paid|overdue|void),
                      issued_at, due_at, paid_at, paid_method(nullable),
                      provider(nullable: szamlazzhu|billingo), provider_ref(nullable),
                      pdf_path(nullable), created_at
                      -- a period zárásakor generálódik, ha commission_net_minor > 0
```

### 5.6 Plan / feature táblák szerepe

A `plans` / `plan_limits` / `plan_features` táblák (docs/02, M1) **megmaradnak**, de a seed átáll **egyetlen `base` plan**-re nagyvonalú limitekkel; a háromlépcsős (basic/mid/max) csomag **megszűnik**. A feature-engedélyezés a `tenant_features`-ön keresztül történik (superadmin, ill. integráció-bekapcsolás). A rátaemelő integrációk (`feature_online_payment`, `feature_invoicing`) szabadon bekapcsolhatók — nem fizetős add-on-ok, csak a jutalékrátát emelik. A migrációs lépést §13/J4 fedi.

> **Add-on-ok (opcionális, később):** ha egy jövőbeli funkciónak valós külső költsége van (pl. SMS/`feature_sms`), az pass-through vagy különálló add-on lehet — ez **nem** része az MVP core monetizációnak, és **nem** érinti a jutalékrátát. P2.

---

## 6. Domain réteg (Action/Service — API-ready, controller-független, docs/01 §4)

### 6.1 `CommissionCalculator` (tiszta domain szolgáltatás, mellékhatás nélkül)

Bemenet: feloldott paraméterek (`F`, `K`), és a period jutalékköteles tételeinek listája `(amount_minor, rate_bps)` időrendben. Kimenet: `CommissionResult { turnover_minor, billable_base_minor, commission_minor, cap_reached }`. A §2.3 algoritmust implementálja. **Tisztán unit-tesztelhető, IO nélkül** — ez kapja a legvastagabb teszt-lefedettséget (§12/1–9).

### 6.2 `RecomputeTenantPeriod` (a ledger → aggregátum újraszámolás)

Bemenet: `tenant_id`, `period`. Lépések egy DB-tranzakcióban:
1. `tenant_billing_periods` sor lock (`firstOrCreate` + `FOR UPDATE`).
2. A `booking_commission_items` `state = billable` tételeinek beolvasása `realized_at` szerint rendezve.
3. `ResolveTenantCommissionSettings` → `F`, `K` (a period hatályos beállítása).
4. `CommissionCalculator` → eredmény.
5. `tenant_billing_periods` frissítése (`turnover_minor`, `commission_minor`, `cap_reached`, `recomputed_at`).
6. `TenantPeriodRecomputed` event (dashboard cache, statisztika).

> Lezárt (`invoiced`/`paid`) period-ot **nem** számol újra (§8.2). A korrekció az aktuális period-ba kerül.

### 6.3 `UpsertBookingCommissionItem` (foglalás-állapotváltásra)

Bemenet: `Booking` az új állapotában. Eldönti a §3.1 szabály szerint, hogy jutalékköteles-e:
- Ha igen, és nincs még tétel → `booking_commission_items` insert (`amount = booking.price_minor`, `rate_bps` = §2.4 snapshot, `realized_at = now`/`starts_at`, `state = billable`, `settings_id`).
- Ha igen, és van tétel → ár-/állapot-szinkron (`state = billable`).
- Ha nem (24h+ lemondás, rejected) → meglévő tétel `state = removed`.
- Végül `RecomputeTenantPeriod` az érintett period-ra. **Idempotens** a `booking_id`-ra (unique).

Kötés eseményekhez: `BookingConfirmed`, `BookingCompleted`, `BookingNoShow`, `BookingCanceled`, (ár-módosítás eventje) → listener hívja ezt az actiont.

### 6.4 `ResolveTenantCommissionSettings`

Tenant + period → effektív paraméterek: a period-re hatályos `commission_settings` + `tenant_commission_overrides` merge. Visszaad egy immutábilis settings DTO-t + a `settings_id`-t (ledger-hez). Az integráció-aktív státusz **foglalásonként** dől el (§2.4), nem itt.

### 6.5 `GenerateCommissionInvoice` (havi, period-záráskor)

Bemenet: `tenant_id`, `period` (lezárandó). Lépések:
1. `RecomputeTenantPeriod` (végső számítás).
2. Ha `commission_minor = 0` → period `status = void` (nincs számla), kész.
3. Egyébként `commission_invoices` insert: `commission_net_minor = commission_minor`, ÁFA számítás (`vat_bps`, `vat_minor`, `total_gross_minor`), `due_at = issued_at + fizetési határidő` (default 8 nap, konfig).
4. Opcionálisan külső számla kiállítása (Számlázz.hu/Billingo, slot4u saját számlázási csatornája) → `provider_ref`, `pdf_path`.
5. period `status = invoiced`, `invoice_id` beállítása.
6. `CommissionInvoiceIssued` event → email a tenantnak (i18n sablon), `integration_logs`.

### 6.6 `MarkCommissionInvoicePaid` / dunning

Befizetés rögzítése (manuális superadmin jelölés vagy később banki egyeztetés) → `status = paid`. Lejárt fizetési határidő → `status = overdue` → emlékeztető emailek → konfigurálható türelmi idő után **tenant felfüggesztés** (`tenants.status = suspended`, docs/03). A felfüggesztett tenant publikus foglalófelülete „átmenetileg nem elérhető", admin belépéskor fizetési oldal.

---

## 7. Ütemezett folyamatok (jobok)

| Job | Ütemezés | Feladat |
|---|---|---|
| `CloseBillingPeriods` | havonta, tenant-tz hónapforduló + türelmi idő (pl. minden hó 1-jén) | minden tenant előző period-jára `GenerateCommissionInvoice` |
| `DunningSweep` | naponta | `overdue` számlák emlékeztetése, türelmi idő utáni felfüggesztés |
| `RecomputeDriftCheck` (opcionális) | naponta | nyitott period-ok aggregátumának egyeztetése a ledgerrel (drift-ellenőrzés) |

A türelmi idő (`grace`) azért kell, mert egy hónap utolsó napján teljesülő foglalás állapota (no-show/completed) néhány órával a hónapforduló után dől el. A period zárása ezt megvárja. Pontos érték: §15.4.

---

## 8. Lemondás, módosítás, refund visszahatása

### 8.1 Nyitott (`open`) period

Bármely állapotváltás (lemondás, no-show, ár-módosítás) → `UpsertBookingCommissionItem` → `RecomputeTenantPeriod`. A forgalom és a jutalék azonnal frissül, a dashboard valós időben követi.

### 8.2 Lezárt (`invoiced`/`paid`) period

Lezárt period-ot **nem** módosítunk visszamenőleg (könyvelési stabilitás). Ha egy már kiszámlázott foglalás utólag változik (pl. késői admin-storno, vagy az ügyfél refundot kap egy tenant-fizetésnél), a korrekció **az aktuális nyitott period-ba** kerül **jóváírásként** (negatív korrekciós tétel / a következő számla csökkentése). Esetek tesztelendők (§12/13). Pontos mechanizmus: §15.5.

### 8.3 Tenant-oldali ügyfél-refund

Ha a tenant a saját fizetési integrációján keresztül visszatérít az ügyfélnek, az **a tenant és az ügyfél** ügye. A slot4u jutalékára ez csak akkor hat, ha a foglalás emiatt jutalékmentes állapotba kerül (pl. teljes storno 24h+ szabály szerint). A részleges ügyfél-refund **nem** csökkenti automatikusan a jutalékalapot (a foglalás listaára változatlan) — kivéve ha a tenant ténylegesen módosítja a foglalás árát (§3.3).

---

## 9. Tenant transzparencia dashboard (a percepciós kockázat ellen)

A tenant billing oldalán (jog: `billing.view`), valós időben, i18n-nel:

- Aktuális period: **forgalom eddig**, **ingyenes keretből hátralévő** (`F − turnover`), **felhalmozott jutalék**, **plafonból hátralévő**, **effektív ráta** (1% vagy 1,5% + magyarázat, miért).
- Vizuális sáv: hol tart a tenant a küszöb → plafon skálán.
- Tételes lista: `booking_commission_items` (foglalás kódja, dátum, listaár, ráta, állapot) — szűrhető period-ra, exportálható. A 24 órán túl lemondott (`removed`) tételek külön jelölve (miért nem számítanak).
- Havi jutalékszámlák listája (`commission_invoices`): period, nettó, ÁFA, bruttó, státusz, PDF letöltés, fizetési határidő.
- Tájékoztató szöveg (kulcs-alapú, tenant-tz/locale): „A foglalási rendszer ingyenes. Jutalékot csak {threshold} feletti havi forgalom esetén számolunk, a feletti részre {rate}, max {cap}/hó. A 24 óránál korábban lemondott foglalások nem számítanak."

N+1 ellenőrzés a tételes listán (DoD).

---

## 10. Superadmin

- `commission_settings` szerkesztő (új verzió létrehozása `effective_from`-mal); a régi verziók read-only history.
- Tenant-szintű `tenant_commission_overrides` szerkesztő (auditolva).
- Jutalékszámlák kezelése: kézi „fizetettnek jelölés", storno, újraküldés; dunning státusz.
- Globális jutalék-statisztika: havi jutalékbevétel (új MRR-proxy), top tenantok forgalom szerint, plafont elérők száma, küszöb alatt „ragadt" (még nem fizető) tenantok aránya és aktiválási funnel, lejárt számlák / felfüggesztés-kockázat.

---

## 11. i18n (lang/hu — hardcoded string TILOS, docs/01 §5)

Új kulcscsoportok (legalább): `billing.commission.*` (forgalom, küszöb, plafon, effektív ráta, tételsorok, export, hátralévő keret), `billing.invoice.*` (jutalékszámla, nettó/ÁFA/bruttó, határidő, státusz, PDF), `billing.rate_notice` (miért 1% vagy 1,5%), `billing.threshold_reached`, `billing.cap_reached`, `billing.late_cancel_notice` (24h szabály), superadmin `admin.commission.*`. Email-sablon kulcsok (tenant-szerkeszthető, docs/02 `message_templates`-hez igazítva, de slot4u→tenant irányban): havi jutalékszámla-értesítő, fizetési emlékeztető, felfüggesztés-figyelmeztetés.

---

## 12. Edge case + Pest teszt-mátrix (tételes, DoD-feltétel)

**`CommissionCalculator` (unit, IO nélkül):**
1. Küszöb alatti összforgalom → 0.
2. Pontosan a küszöbön → 0.
3. Küszöböt átlépő forgalom → csak a feletti rész jutalékköteles (marginális).
4. Több foglalás, ami együtt lépi át a küszöböt → az összjutalék a küszöb feletti összforgalom rátája.
5. Plafon pontosan elérve → onnantól a period jutaléka nem nő.
6. Plafont átlépő forgalom → jutalék a plafonra vágva.
7. `F = 0` és `K = null` → első fillértől, korlátlanul.
8. Kerekítés lefelé, determinisztikusan (pl. 333 minor × 100 bps = 3, nem 3,33).
9. Vegyes ráta a tételeken (1% és 1,5%) időrendben → a küszöböt a korábbi tételek töltik, az emelt ráta csak a feletti, később keletkezett forgalmat terheli.

**Integráció / állapot (feature/DB):**
10. 24 órán **túli** lemondás → a tétel `removed`, nem számít; a forgalom csökken.
11. 24 órán **belüli** lemondás és `no_show` → **számít** (bennmarad).
12. `requested`/`approved`/`rejected` foglalás → soha nem keletkeztet ledger-tételt, amíg nem `confirmed`.
13. Refund / lezárt period utólagos változása → korrekció az aktuális period-ban (negatív tétel), a lezárt period érintetlen.
14. Rátaemelő integráció aktiválása hónap közben → az aktiválás utáni foglalások 1,5%, a korábbiak 1% (nem retroaktív).
15. Period-határ tenant-tz szerint; **DST-átállásos** hónap (Europe/Budapest, március/október) — a period helyesen zár; a hónap utolsó napi foglalás a helyes period-ba esik.
16. `commission_settings` verzióváltás hónap közben → a period a period-en belül hatályos beállítással számol (audit-rekonstrukció).
17. Suspended tenant: nyitott period számítása korrekt; új foglalás a publikus felületen nem indítható.
18. Idempotencia: ugyanazon `booking_id`-ra kétszer hívott `UpsertBookingCommissionItem` → egy tétel.
19. Konkurencia: két párhuzamos állapotváltás ugyanarra a period-ra → a sor-lock + ledgerből-újraszámolás helyes végösszeget ad.
20. ÁFA-számítás: `commission_net_minor` → `vat_minor` → `total_gross_minor` integer-aritmetikával, kerekítés rögzítve.

**Tenant-izoláció (KÖTELEZŐ minden új modellre, docs/01 DoD):**
21. `booking_commission_items`, `tenant_billing_periods`, `tenant_commission_overrides`, `commission_invoices` — cross-tenant olvasás/írás tiltva; cross-tenant ID → `404` (nem `403`).

---

## 13. Linear epic + sub-issue bontás (sorrend, függőségek, milestone)

**Epic:** „Forgalom-alapú jutalék árazási modell (havi számlázás)". **Cél-milestone:** túlnyomóan **M6** (Előfizetés, fizetés, számlázás); a séma-előkészítés és a base-plan átállás M1-et is érinti.

| # | Issue | Tartalom | Függ | Milestone |
|---|---|---|---|---|
| J1 | Docs-of-record | Ez a `docs/10` (v2) + a 02/03/05 frissítése (§16). Kód nincs. | — | M6 |
| J2 | Séma: jutalék-táblák | `commission_settings`, `tenant_commission_overrides`, `booking_commission_items`, `tenant_billing_periods`, `commission_invoices` migrációk + modellek + `BelongsToTenant` + factory-k + izolációs teszt. | J1 | M6 |
| J3 | Base-plan átállás | háromlépcsős csomag eltávolítása, egyetlen `base` plan seed nagyvonalú limitekkel, `tenant_features` útvonal, superadmin felület igazítása. | J1 | M6 (séma M1-et érint) |
| J4 | Domain: Calculator + Resolver | `CommissionCalculator` + `ResolveTenantCommissionSettings` + a §12/1–9 unit-tesztek. | J2 | M6 |
| J5 | Ledger + újraszámolás | `UpsertBookingCommissionItem` + `RecomputeTenantPeriod`, foglalás-eventekre kötés, idempotencia, sor-lock. | J4 | M6 |
| J6 | Havi számlázás + dunning | `GenerateCommissionInvoice` + ÁFA + `CloseBillingPeriods` job + `MarkCommissionInvoicePaid` + `DunningSweep` + felfüggesztés. | J5 | M6 |
| J7 | Tenant transzparencia dashboard | Inertia/React billing oldal (§9), i18n, N+1, számla-PDF letöltés. | J5 | M6/M7 |
| J8 | Superadmin konfig + statisztika | settings/override UI, jutalékszámla-kezelés, globális jutalék-stat (§10). | J6 | M7 |
| J9 | Rátaemelő integráció bekötés | a `feature_online_payment` / `feature_invoicing` aktív státusz beolvasása a ráta-snapshotba (§2.4); a tényleges Barion/Stripe/Számlázz.hu integráció a meglévő M6 issue-kból jön. | J5 | M6 |

**Kritikus út:** J1 → J2 → J4 → J5 → J6 → J7/J8. A J3 (base-plan átállás) párhuzamosan futhat J2-vel.

> **Megjegyzés a v1 J1-hez:** a v1 blokkoló jogi spike (payment-facilitator) **megszűnik** — a v2 modellben a slot4u nem kezel ügyfélpénzt (§4). A tenant-oldali ügyfél-fizetés (Barion/Stripe) továbbra is a meglévő M6 scope, de az a jutalék-beszedéstől független.

---

## 14. Definition of Done (az epic minden issue-jára, docs/01)

Acceptance criteria + : Pest zöld, Pint/Larastan/ESLint tiszta, i18n betartva (nincs hardcoded UI string), **Form Request + Policy** minden új végponton, **tenant-izoláció igazolt** (`BelongsToTenant` + erre teszt minden új modellen), **pénz integer minor + ráta bps (nincs float)**, N+1 ellenőrizve a billing listázókon, idő UTC tárolva / tenant-tz csak megjelenítéskor, és a docs frissítve, ha a viselkedés eltér.

---

## 15. Döntések (Daniel döntött — 2026-06-27)

> Mind a 7 pont eldöntve. Az alábbi értékek a kötelező defaultok az implementációhoz.

1. **ÁFA a jutalékon: ✅ NETTÓ + 27% ÁFA.** A `commission_minor` a nettó szolgáltatási díj; a 10 000 / 50 000 Ft küszöb/plafon **nettó** értékek; a havi jutalékszámla erre számít rá 27% ÁFÁ-t (`vat_bps=2700`, `vat_minor`, `total_gross_minor` integer).
2. **Base-tier limitek: ✅ 3 dolgozó / 1 helyszín / 3 helyiség** az ingyenes `base` planen, efelett későbbi limit-emelés/add-on. (A `PlanLimitService` és a J3 base-plan seed ezt használja.)
3. **Fizetési határidő + dunning: ✅ 8 nap határidő + 14 nap türelem,** majd felfüggesztés (J6 dunning).
4. **Period-zárási türelmi idő: ✅ a következő hónap 2. napján** zárjuk a period-ot (a hónap végi foglalások állapota addigra véglegesül).
5. **Lezárt period korrekciója: ✅ negatív korrekciós tétel az aktuális (nyitott) period-ban;** a lezárt period változatlan marad (jóváírás a következő számlán).
6. **Időpont nélküli mód 24h-szabálya: ✅ a `confirmed`-be lépéstől számított 24h grace** a default (§3.1), módspecifikus finomítással.
7. **Multi-currency: ✅ MVP-ben tenantonként egy pénznem;** vegyes pénznemű forgalom kizárva. Külön projekt, ha kell.

## 16. Kötelező doc-frissítések (J1 része, ugyanabban a PR-ban)

- **docs/02 §„Tenant & előfizetés" és §„Fizetés & számlázás":** új jutalék-táblák (`commission_settings`, `tenant_commission_overrides`, `booking_commission_items`, `tenant_billing_periods`, `commission_invoices`); a `plans` szerep újraértelmezése (egyetlen base plan).
- **docs/03 §„Csomagok":** a háromlépcsős csomag-tábla **törlése**, helyette base-tier + forgalom-alapú jutalék leírása; a „Trial és státuszátmenetek" igazítása (nincs csomag-lefokozás; van jutalékszámla-fizetés és nemfizetés→felfüggesztés).
- **docs/05 M6:** a milestone hatóköre a recurring-csomag helyett **forgalom-alapú jutalék-motor + havi jutalékszámlázás + dunning**; a base-plan átállás említése. A v1 jogi kapu **törlése**.
- **docs/01 §2 (Jogosultság ≠ Feature ≠ Csomag):** a „Subscription plan limit" réteg pontosítása (egyetlen base plan limitjei; a csomag mint funkció-bundle megszűnik).
- **CLAUDE.md** (gyökér): bárhol, ahol a háromlépcsős csomag vagy a fix előfizetés szerepel, a forgalom-alapú jutalékra cserélni.
