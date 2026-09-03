# 20 — Demo tenantok és seed-rendszer (specifikáció)

**Státusz:** jóváhagyásra kész spec — implementációra Claude Code-nak átadható.
**Kontextus:** M1–M8 lényegében kész (M3/M5/M6 ~90%). Ez a feladat a launch-előkészítés része: 4 kidolgozott célközönség-personához tartozó, éles üzleti használatot imitáló demo tenant, sales/marketing célra a staging környezetben.
**Kapcsolódó docs:** 02 (adatmodell), 03 (jogosultságok/csomagok), 04 (foglalási módok), 05 (fázisterv).

**Daniel döntései (2026-08-30):** 4 persona teljes lefedéssel · újragenerálható artisan parancs (`demo:seed` / `demo:reset`) · elsődleges cél: publikusan mutogatható sales/marketing demo stagingen.

---

## 1. Cél és tervezési elvek

1. **Minden képesség látszódjon.** A 4 persona együtt lefedi mind a 6 foglalási módot, mind a 3 csomagot, a multi-location működést, a várólistát, a jóváhagyási flow-t, az ajánlatkérést, az online fizetést és a számlázást.
2. **Élethű, nem "lorem ipsum".** Reális magyar nevek, szolgáltatásnevek, árak (HUF), munkarendek. Az érdeklődő szolgáltató magára ismerjen a saját personájában.
3. **Mindig friss.** Minden dátum a seed futtatásának napjához képest relatív (Carbon::today() bázis): van múltbeli előzmény ÉS jövőbeli, foglalható naptár. A demo sosem néz ki "halottnak".
4. **Determinisztikus.** Fix faker seed personánként — két futtatás ugyanazt az adatot adja (screenshotok, tesztek, dokumentáció konzisztens marad).
5. **Biztonságos.** Demo tenant sosem küld valós emailt/SMS-t, sosem indít valós fizetést, sosem állít ki valós számlát. Production környezetben a demo-parancsok csak `is_demo` tenantokra hathatnak.
6. **Plan-konzisztens.** A seedelt mennyiségek betartják az adott csomag limitjeit (`plan_limits`) — a demo egyben a csomagrendszer helyes bemutatása is.

---

## 2. A 4 demo persona

Belépési adatok egységesen: minden demo user jelszava `Slot4uDemo!2026`. Email minta: `{szerep}@{slug}.demo.slot4u.hu` (nem kézbesíthető domain — szándékosan).

### 2.1 „Lélekút Pszichológiai Rendelő" — solo pszichológus · **Alap csomag** ✅ *(SLO-184, kész)*

A legkisebb életképes használat: egyszemélyes praxis, diszkrét működés. Ezt látja az egyéni szolgáltató (pszichológus, coach, dietetikus, masszőr) érdeklődő.

- **Subdomain:** `demo-pszichologus` · timezone Europe/Budapest · locale hu
- **Struktúra:** 1 helyszín (belvárosi rendelő), 1 helyiség, 1 staff (dr. Vas Emese — ő maga a tenant admin user is, `staff.user_id` kitöltve), 1 admin user (limit: 1 admin, 3 staff)
- **Szolgáltatások:**
  - *Első konzultáció* — 50 perc, 22 000 Ft, `duration_based` + `requires_approval = true` (előszűrés: az 5. mód demója), buffer 10 perc
  - *Egyéni konzultáció* — 50 perc, 18 000 Ft, `duration_based`, buffer 10 perc
  - *Online konzultáció* — 50 perc, 16 000 Ft, `duration_based`
  - *Igazolás / dokumentum kérése* — `no_time_slot`, `fulfillment_type = manual`, 5 000 Ft (1. mód demója)
- **Munkarend:** H–Cs 9:00–17:00, P 9:00–13:00; 1 jövőbeli szabadnap `schedule_exceptions`-ben (kivételkezelés látszódjon a naptáron)
- **Ügyfelek:** 10 · **Előzmény:** ~90 nap, heti 8–12 foglalás; jövőben 2 hét részben foglalt naptár + legalább 1 `requested` (jóváhagyásra váró) és 1 `rejected` kérés
- **⚠ Tartalmi szabály:** a jegyzet/megjegyzés mezőkben SEMMI egészségügyi-jellegű tartalom (fiktíven sem — különleges adatkategória, rossz üzenet demóban). Csak semleges szövegek: „Kapucsengő: 12", „Első alkalom", „Átütemezést kért".

> **Megvalósítási megjegyzés (SLO-184, 2026-09-03) — a „csomag" itt már nem csomag.**
> A lépcsős csomagmodell (Alap/Közepes/Max) azóta **megszűnt** (CLAUDE.md, `docs/10`): egyetlen
> ingyenes `base` plan van, platformszintű mennyiségi limitekkel (l. lentebb), és a monetizáció
> forgalom-alapú jutalék. Az „Alap csomag" cimke ezért ennél a
> personánál **a méretet jelenti, nem egy megvásárolható szintet** — a solo praxis bőven belefér
> a `base` limitekbe, és ezt teszt őrzi.
>
> Ebből következik, hogy a §5.3 szerinti **upsell-demó („Válts Közepes csomagra" ajtó) tárgytalan**:
> nincs nagyobb csomag, amire váltani lehetne. A „lezárt funkció + bekapcsolási CTA" minta viszont
> él — a `feature_branding` alapból kikapcsolt a `base` planen, és a beállítások képernyő ezért
> mutat zárolt szekciót (SLO-21) —, csak ez superadmin-kapcsoló, nem vásárlás. Ha a demóban mégis
> kell egy értékesítési ajtó, az termékdöntés (Daniel), nem hiányzó UI-elem.
>
> **Limit-emelés (SLO-195, 2026-09-03).** A fenti personák közül három nem fért bele az akkori
> base limitekbe (3 dolgozó / 1 helyszín / 3 helyiség), és a lefedettségi mátrix **multi-location ✔**
> cellája egyenesen lehetetlen volt. Daniel döntése nyomán a base plan limitjei
> **8 dolgozó / 3 helyszín / 8 helyiség** (`docs/10` §15.2) — így mind a négy persona
> plan-konzisztensen épül, tartalékkal, és a látogató a saját fiókjában újra tudja építeni, amit lát.
>
> Két további, implementáció közben rögzített részlet: a szolgáltatás `staff`/`rooms` pivotja
> **kötelező**, különben az `AvailabilityService` nem talál erőforrást és a publikus oldal egyetlen
> szabad idősávot sem kínál; a `fulfillment_type` pedig nem oszlop, hanem a `services.settings`
> JSON kulcsa.

### 2.2 „GlamZone Szépségszalon" — több dolgozós szalon · **Közepes csomag** ✅ *(SLO-185, kész)*

A klasszikus KKV-ügyfél: fodrász, kozmetikus, körmös egy fedél alatt. Itt látszik a staff-választás, a branding-testreszabás és a statisztika modul.

- **Subdomain:** `demo-szepsegszalon` · branding: saját színpár + logó (a `branding` json kitöltve — a Közepes csomag testreszabás-feature demója)
- **Struktúra:** 1 helyszín, 3 helyiség (Fodrász tér, Kozmetika, Körmös stúdió), 4 staff (2 fodrász, 1 kozmetikus, 1 körmös), 2 admin user (tulajdonos + recepciós Manager role-lal — a szerepkör-különbség demója)
- **Szolgáltatások** (3 kategória: Fodrászat / Kozmetika / Kéz-láb): 8–10 db `duration_based`, eltérő időtartamokkal és bufferekkel — pl. *Női hajvágás* 60 p / 12 500 Ft, *Festés+vágás* 120 p / 24 000 Ft, *Férfi hajvágás* 30 p / 6 500 Ft, *Arckezelés* 75 p / 15 000 Ft, *Géllakk* 60 p / 9 000 Ft. Fodrász szolgáltatásoknál „bárki" (staff-választás nélküli) foglalás is engedélyezve.
- **Munkarend:** staffonként ELTÉRŐ (váltott műszak, szombati nyitás egy staffnál) — a naptárszűrés (helyiség/dolgozó) így mutat valamit
- **Ügyfelek:** 35 · **Előzmény:** ~180 nap, napi 6–10 foglalás → a statisztika modul (ügyfélköltés, dolgozói aktivitás, kihasználtság) értelmes görbéket mutat; visszatérő ügyfelek (top ügyfélnek 15+ foglalása)
- **Extra:** 1 testreszabott `message_template` (visszaigazoló email szalon-hangnemben) + néhány tenant↔ügyfél üzenetváltás (M5 demó)

> **Megvalósítási megjegyzések (SLO-185, 2026-09-03).** Három ponton tér el a megvalósítás
> az adatlaptól, mindhárom kényszerből:
>
> 1. **4 helyiség, nem 3.** Az adatlap közös „Fodrász tér"-t ír két fodrásszal — a foglalási motor
>    ezt nem tudja kifejezni: a helyiség **kizárólagos erőforrás** (a `CreateBooking` ütközés-
>    vizsgálata `staff_id OR room_id`-ra illeszt), így két egyszerre dolgozó fodrász ugyanabban a
>    helyiségben dupla foglalás lenne, és a szalon minden második időpontja elutasításra kerülne.
>    A közös tér ezért két székre (két helyiségre) bomlik; a három funkcionális zóna a nevekben él
>    tovább. Az alternatíva — `room_id` nélküli fodrász-foglalások — a helyiség-kihasználtsági
>    riportból pont a szalon legforgalmasabb felét hagyta volna ki.
> 2. **180 ügyfél, nem 35.** Az adatlap két száma nem lehet egyszerre igaz: fél év napi 6–10
>    foglalással ~1200 időpont, ami 35 emberre osztva **négynaponkénti** fodrászlátogatás fejenként.
>    Szó szerint seedelve a top ügyfélnek 135 foglalása lett. A napi volumen a megtartandó fél
>    (ebből lesz a statisztika hat hónapos görbéje, §2.2 AC), ezért a névsor nőtt a volumenhez —
>    így a „törzsvendég" havi rendszerességet jelent, nem képtelenséget. Teszt őrzi mindkét irányban.
> 3. **Az üzenetváltás kimaradt.** A testreszabott `message_template` megvan; az általános
>    tenant↔ügyfél üzenetszál viszont **nincs megépítve** (csak `quote_request_messages` létezik,
>    ajánlatkéréshez kötve) — a hiányzó darab az **SLO-36** (M5). A lefedettségi mátrix
>    „Üzenetküldés ✔" cellája a szalonnál addig nem pipálható.
>
> **A branding nem a `branding` jsontól látszik:** a `feature_branding` a `base` planen alapból
> kikapcsolt, ezért a persona a superadmin per-tenant felülírását (`tenant_features`) is megírja —
> így az az útvonal is demózódik.
>
> **Seed-költség:** a mély előzmény értesítés nélkül készül (`DemoDataFactory::NOTIFY_WINDOW_DAYS`,
> 21 nap) — egy fél éve volt időpont visszaigazoló levele sem az inboxban, sem a
> `notifications_log`-ban nem kívánatos, és a levélrenderelés a seed legdrágább lépése volt.
> Ezzel együtt a szalon seedje **10 perc → 1 perc 49 mp** (MariaDB).

### 2.3 „Premium Fitness Studio" — fitnesz/edzőterem · **Max csomag** ⭐ a sales-demo zászlóshajója

Itt van minden: csoportórák várólistával, személyi edzés, teremfoglalás, online fizetés, számlázás, két telephely.

- **Subdomain:** `demo-fitnesz` · a Max csomag minden feature-e bekapcsolva
- **Struktúra:** 2 helyszín (Buda, Pest — az SLO-51 multi-location demója: egy edző mindkét helyszínen, helyszínenként eltérő munkarenddel), 4 helyiség (nagyterem, kisterem, szauna, PT-box), 6 staff, 3 admin (tulajdonos, 2 recepciós — 1 Manager, 1 Employee)
- **Szolgáltatások:**
  - *Csoportórák* — `event_based`: heti ismétlődő órarend (~15 esemény/hét: Functional, Jóga, Spinning...), kapacitás 10–16 fő, `waitlist_enabled = true`; 3 900 Ft/alkalom. Legalább 2 jövőbeli esemény TELE + aktív várólistával (3. mód + várólista demó); 1 esemény `party_size > 1` jelentkezéssel
  - *Személyi edzés* — 60 perc, 14 000 Ft, `duration_based`, edzőválasztással
  - *Szaunabérlés* — `resource_rental`, 60 perc, 8 000 Ft (4. mód demója)
  - *PT-box terembérlés külső edzőnek* — `resource_rental`, szabad időtartam 60–180 perc, `settings`-ben min/max
- **Fizetés + számla:** online fizetés kötelező a csoportórákra és PT-re; `payments` előzmények (paid/failed/refunded vegyesen), `invoices` rekordok, 1-2 `refund` (lemondott esemény) — MINDEN sandbox/fake provider_ref-fel (lásd 4.4)
- **Ügyfelek:** 60 · **Előzmény:** ~180 nap sűrű forgalom → dashboard „wow-állapot": bevétel-görbe, kihasználtság, élő bento grid
- **Állapot-lefedettség:** `pending_payment`, `no_show`, `canceled`, várólista `waiting/offered/converted` — minden státusz megtalálható

### 2.4 „Pelso Rendezvényház" — rendezvényhelyszín · **Közepes csomag**

A magas kosárértékű, ajánlat-alapú üzlet demója — és annak bizonyítéka, hogy a rendszer nem csak „időpontfoglaló".

- **Subdomain:** `demo-rendezvenyhaz`
- **Struktúra:** 1 helyszín, 3 helyiség (Nagyterem 120 fő, Panoráma terasz 60 fő, Tárgyaló 20 fő), 2 staff (rendezvényszervező, gondnok), 1 admin
- **Szolgáltatások:**
  - *Rendezvény ajánlatkérés* — `quote_request` (6. mód demója): paraméterek a `parameters` json-ban (dátum, létszám, esemény típusa, catering igény); ajánlatok MINDEN státuszban: `new`, `in_progress`, `quoted` (árral+érvényességgel), `accepted` (generált bookinggal), `rejected`; üzenetváltás legalább 1 kérelmen belül
  - *Tárgyalóbérlés* — `resource_rental`, óradíj 15 000 Ft, `requires_approval = true` (erőforrás + jóváhagyás kombináció)
  - *Helyszínbejárás* — 45 perc, ingyenes (0 Ft), `duration_based` a szervezővel
- **Előzmény:** kevés, de nagy értékű tétel (~90 nap, heti 2–4 esemény) — a „kevés foglalás, nagy bevétel" statisztika-mintázat

### Lefedettségi mátrix (ellenőrzőlista a seedhez)

| Képesség | Pszichológus | Szalon | Fitnesz | Rendezvényház |
|---|---|---|---|---|
| Csomag | Alap | Közepes | **Max** | Közepes |
| duration_based | ✔ (core) | ✔ (multi-staff) | ✔ | ✔ |
| no_time_slot | ✔ | — | — | — |
| event_based + várólista | — | — | ✔ | — |
| resource_rental | — | — | ✔ | ✔ |
| manual_approval | ✔ | — | — | ✔ |
| quote_request | — | — | — | ✔ |
| Multi-location | — | — | ✔ | — |
| Online fizetés + számla | — | — | ✔ | — |
| Branding testreszabás | — | ✔ | ✔ | — |
| Statisztika „wow" | — | ✔ | ✔✔ | ✔ |
| Manager/Employee szerep demó | — | ✔ | ✔ | — |
| Üzenetküldés | — | ✔ | — | ✔ (quote-on) |

---

## 3. Technikai specifikáció

> **Megvalósítási megjegyzés (SLO-182 / SLO-183, 2026-09-02).** A spec eredetileg `docs/16` néven készült,
> de a repóban a 16-os számot már a `docs/16-deploy-pipeline.md` viszi (a CLAUDE.md doksi-táblája
> hivatkozik rá), ezért ez a fájl **20-as sorszámmal** került be. Az `is_demo` flag és az alábbi
> guardrailek (SLO-182) és a `demo:seed` / `demo:reset` keretrendszer (3.2–3.3, SLO-183) készen
> vannak. A keretrendszert egy minimális **smoke persona** (`demo-smoke`) és — az SLO-184 óta — az
> első valódi persona (`demo-pszichologus`, §2.1) igazolja; a maradék három (§2.2–2.4) az
> SLO-185..190 issue-kben készül.


### 3.1 `is_demo` flag és guardrailek

- Új migráció: `tenants.is_demo` boolean, default false, indexelt. (NEM settings json — kritikus guardrail-feltétel, legyen explicit oszlop.)
- **Guardrailek** (mindegyikre Pest teszt):
  - `demo:seed` / `demo:reset` kizárólag `is_demo = true` tenantot törölhet/írhat felül; nem-demo tenant slug-ütközésnél a parancs hibával leáll.
  - Demo tenantnál minden kimenő értesítés (email/SMS) elnyomva: a notification réteg demo tenantnál `log` csatornára vált, de a `notifications_log`-ba ugyanúgy ír (a demóban látszódjon, MI ment volna ki — ez feature, nem hiány).
  - Demo tenant fizetési szolgáltatója mindig sandbox/fake (lásd 3.4); Számlázz.hu hívás demo tenantból TILOS — fake invoice rekord készül `provider_ref = 'DEMO-...'`-val.
  - Superadmin felületen a demo tenant kap egy „DEMO" badge-et; a globális statisztikákból (MRR, aktív tenantok) a demo tenantok kiszűrve.
  - ~~Demo tenant `subscriptions` rekordja `active`, de fizetési provider nélkül~~ → **a megvalósításban másképp** (SLO-182): `subscriptions` tábla nincs, a lépcsős csomagmodell megszűnt (CLAUDE.md, docs/10). A mai megfelelője a **jutalék-számlázásból való kizárás**: a `billing:close-periods` átugorja a demo tenant nyitott időszakait (nem zárja, nem állít ki jutalékszámlát), a `billing:dunning-sweep` pedig sem nem sürget, sem nem függeszt fel. Ez utóbbi a lényeg: a felfüggesztés a publikus felületet zárja le, ami egy demo tenantnál maga a termék — enélkül a sales-demo 22 nap után magától elsötétülne.

### 3.2 Parancsok

> **⚠️ Megvalósítási megjegyzés (SLO-183) — a bontás sorrendje biztonsági kérdés.**
> A `users.tenant_id` idegen kulcsa `nullOnDelete`, és ebben a kódbázisban a
> `tenant_id === null` **maga a platform-superadmin definíciója** (`User::isSuperAdmin()`).
> A kézenfekvő bontás — „töröljük a tenantot, a cascade elintézi a többit" — ezért nem
> árvává tenné a demo fiókokat, hanem **superadminná léptetné elő őket**: minden éjszakai
> `demo:reset` egy friss adag platform-adminnal, amelyek jelszava ebben a dokumentumban
> publikált, e-mail címe pedig az aldoménből kitalálható. A `PurgeDemoTenant` ezért a
> usereket **explicit, a tenant előtt** törli, és erre teszt van. Persona-seeder írásakor
> semmilyen körülmények között ne kerüld meg ezt a szolgáltatást.
>
> A többi tenant-tábla a `cascadeOnDelete` idegen kulcsokon megy (31 `tenant_id` oszlopból
> 29 ilyen), tehát egy később hozzáadott, szokásos cascade-del ellátott tábla magától
> rendben lesz. A két kivétel a `users` (fent) és az `audit_logs` (nincs rajta constraint,
> ezért szintén explicit törlődik).


```
php artisan demo:seed {--tenant=slug : csak egy persona} {--fresh : törlés + újraépítés}
php artisan demo:reset                  # = demo:seed --fresh minden demo tenantra
```

- Idempotens: `--fresh` nélkül a létező demo tenantot érintetlenül hagyja; `--fresh`-sel (FK-sorrendben) törli és újraépíti.
- Struktúra: `database/seeders/Demo/DemoSeeder.php` (orchestrator) + personánként egy osztály (`PsychologistDemoSeeder`, `SalonDemoSeeder`, `FitnessDemoSeeder`, `VenueDemoSeeder`) + közös `DemoDataFactory` helper (nevek, relatív dátumok, faker seed kezelés).
- Determinizmus: personánként fix faker seed; MINDEN dátum `Carbon::today()`-hoz relatív (pl. „-90 nap … +14 nap"). Kivétel: a napon belüli időpontok a munkarend-rácsra esnek.
- Staging: éjszakai ütemezett `demo:reset` (scheduler, pl. 03:00 Europe/Budapest) — a látogatók által összepiszkált demo minden reggel tiszta. A scheduler-bejegyzés csak akkor fut, ha van demo tenant.

### 3.3 Előzmény-generálás — állapot-korrekt módon

A múltbeli foglalásokat NEM nyers inserttel, hanem a meglévő Action/Service rétegen keresztül (vagy azzal ekvivalens, állapotgépet tisztelő factory state-ekkel) kell létrehozni, hogy:

- a `booking_status_history` minden foglalásnál konzisztens legyen (requested→approved→confirmed→completed lánc),
- az ütközésvédelem érvényesüljön (a demo adat garantáltan ütközésmentes),
- ~~a `daily_stats` aggregátum a seed végén lefuttatott aggregáló jobbal töltődjön~~ → **tárgytalan** (SLO-183): `daily_stats` tábla nincs, és nem is kell — a tenant dashboard (`BuildTenantDashboard`) és a riportok (`BuildTenantReport`) élőben számolnak a `bookings`-ból. A seedelt foglalások tehát azonnal és aggregálás nélkül megjelennek; egy külön aggregáló lépés csak egy második igazságforrás lenne.

Múltbeli foglalásoknál az időbélyegek (created_at, history) visszadátumozása megengedett — erre a seeder kapjon dedikált helpert, ne ad-hoc `timestamps = false` hackeket.

### 3.4 Fizetés demo tenantnál

- M6 provider-absztrakcióra építve: demo tenant `FakePaymentProvider`-t kap (azonnal `paid` státusz, determinisztikus `provider_ref`), VAGY ha a Barion/Stripe sandbox stagingen már bekötött, akkor sandbox kulcsokkal megy — de a seedelt ELŐZMÉNY mindig fake rekord.
- A publikus demo fizetési lépése a látogatónak is végigjátszható legyen kártyaadat megadása nélkül (fake provider „Sikeres fizetés" képernyővel) — a sales-demo legfontosabb pillanata.

### 3.5 Tesztek (DoD)

- Pest: `demo:seed` lefut üres DB-n és meglévő demo adaton (`--fresh`) is.
- Invariáns-tesztek: nincs ütköző foglalás; plan limitek betartva personánként; mind a 6 booking mode képviselve; minden BookingStatus előfordul legalább egyszer a teljes demo készletben; demo tenantból nem megy ki valós értesítés.
- Tenant-izoláció: demo tenantok egymás adatait nem látják (meglévő izolációs teszt-minta újrahasznosítható).

---

## 4. Javasolt Linear issue-bontás (M8-ba vagy külön „Demo" milestone-ba)

Egy issue = egy PR, a megszokott flow szerint. Javasolt sorrend és függések:

1. **`is_demo` flag + guardrailek** — migráció, notification-suppress, superadmin badge, statisztika-szűrés, Pest tesztek. *(blokkolja az összes többit)*
2. **Demo seed keretrendszer** — `demo:seed`/`demo:reset` parancs, orchestrator, DemoDataFactory, relatív dátum + visszadátumozó helper, determinizmus. *(blokkolja a personákat)*
3. **Persona: Lélekút Pszichológiai Rendelő** — a legkisebb, ezzel validálódik a keretrendszer.
4. **Persona: GlamZone Szépségszalon** — multi-staff, branding, statisztika-sűrűség.
5. **Persona: Premium Fitness Studio** — events+várólista+resource+fizetés+számla+multi-location; FakePaymentProvider ide tartozik. *(a legnagyobb — szükség esetén sub-issue-k: törzsadat / események+várólista / fizetési előzmények)*
6. **Persona: Pelso Rendezvényház** — quote flow minden státuszban, üzenetváltással.
7. **Staging ütemezett `demo:reset` + üzemeltetés** — scheduler, futás-log, hibaalarm.
8. **Publikus demo belépőpont** *(opcionális, marketing)* — slot4u.hu landingen „Próbáld ki élőben" szekció: 4 persona-kártya → demo subdomain + előre kitöltött belépés (vagy egykattintásos demo-login token). Acceptance: látogató 2 kattintásból egy élő demo admin dashboardon áll.

**Acceptance criteria a teljes feladatra:** `php artisan demo:reset` után mind a 4 demo tenant elérhető a subdomainjén; a lefedettségi mátrix (2. fejezet) minden ✔ cellája kattintással bemutatható; a dashboardok adatokkal teltek; valós email/fizetés/számla nem keletkezhet; a parancs újrafuttatása azonos állapotot ad.

---

## 5. Üzletfejlesztői észrevételek (Daniel figyelmébe)

1. **A demo nem fejlesztési segédeszköz, hanem a sales funnel első lépcsője.** A 4 persona legyen 1:1 azonos a landing „Kinek való?" szekciójával (ugyanaz a 4 név, ikon, szín) — a látogató a landingen felismeri magát, a demóban pedig „belelép" a saját üzletébe. A demo végén mindig legyen CTA: „Indítsd el a saját rendszered — 14 nap ingyen" (a trial-flow-ba kötve).
2. **A dashboard az első benyomás.** A seed-adat mennyiségét a dashboard/statisztika „wow-állapotához" méretezzük (ezért a 180 napos előzmény a szalonnál/fitnesznél), ne a minimumhoz. A screenshotok, a landing képei és a bemutató videók ugyanebből a determinisztikus adatból készülhetnek — konzisztens marketing-anyag nulla plusz munkával.
3. **Upsell beépítve:** a pszichológus (Alap) demóban szándékosan legyen látható egy elért limit vagy egy kikapcsolt feature (pl. statisztika modul „Válts Közepes csomagra" ajtóval) — a demo így a csomag-upgrade útvonalat is eladja.
4. **Reális árak = bizalom.** A demo árak a valós magyar piaci sávban legyenek — az érdeklődő azonnal fejben számol („napi 8 vendég × 12 500 Ft…"), és ez a legerősebb értékesítési pillanat.
5. **Etikai határ a pszichológus personánál:** egészségügyi jellegű adat fiktíven se jelenjen meg. Ez jogi (különleges adatkategória asszociáció) és üzenet-szinten is fontos: a demo azt sugallja, hogyan bánik a rendszer az érzékeny szektorral.
6. **Írható demo + napi reset a jó kompromisszum.** A kipróbálható (foglalást engedő) demo sokkal erősebb élmény, mint a read-only; a napi éjszakai reset kezeli a kockázatot. Később (P2) jöhet per-látogató sandbox.
7. **Mérj!** A demo tenantok publikus oldalára kerüljön analitika (GA4 vagy ami a landingen lesz): melyik personát nézik, meddig jutnak a foglalási flow-ban, hol esnek ki — ez lesz az első valós piaci visszajelzés arról, MELYIK célközönségre érdemes a marketing-büdzsét költeni a launchnál.
8. **A demo egyben örökös regressziós smoke-teszt:** ha a `demo:reset` bármikor elhasal, az korai jelzés arra, hogy egy változás eltörte a foglalási motort. Érdemes CI-ban is futtatni (SQLite/MySQL service ellen).
