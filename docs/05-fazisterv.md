# 05 — Fázisterv (= Linear milestone-ok)

Minden fázis önállóan tesztelhető, demózható állapotban zárul. Egy Linear issue = egy PR. Az issue-k a "Slot4U MVP – SaaS foglalási rendszer" projektben élnek (SLO team).

## M0 — Product Discovery & Scope Lock ✅ (ez a dokumentáció zárja)

A 3 discovery issue (SLO-5 tenant architektúra, SLO-6 RBAC, SLO-7 foglalási motor) kimenete a `docs/01–04` dokumentum. Elfogadás után M0 zárható.

## M1 — Alapinfrastruktúra & tenant alaprendszer

Repo, Docker, CI, Laravel+Inertia+React skeleton, i18n alap, multi-tenancy (subdomain, BelongsToTenant, middleware lánc), auth, spatie RBAC seed, Pennant feature flagek, plans/subscriptions séma + PlanLimitService, superadmin tenant CRUD (minimál), audit log alap.
**Demó:** tenant regisztráció → subdomain → belépés tenant adminként, üres dashboard; superadmin látja/felfüggeszti a tenantot.

## M2 — Tenant törzsadatok

Helyszínek, helyiségek, dolgozók, szolgáltatáskategóriák+szolgáltatások (mind a 6 mód beállításai), árak, munkarend + kivételek, esemény-meghirdetés, cégprofil+branding. Admin UI (shadcn, dark mode, bento grid keret).
**Demó:** tenant admin teljes törzsadatot rögzít.

## M3 — Foglalási motor (backend)

AvailabilityService, slot-generálás, a 6 mode strategy, ütközésvédelem, állapotgép + history, várólista, jóváhagyási flow, ajánlatkérés flow, lemondási szabályok. Vastag Pest teszt-lefedettség az edge case-ekre (lásd 04).
**Demó:** API/teszt szinten minden foglalási mód működik.

## M4 — Publikus foglalófelület + members area

Tenant publikus oldal: szolgáltatáslista, naptár/slot-választó, szűrés (helyszín/helyiség/dolgozó/szolgáltatás), foglalási flow mind a 6 módra (kártya-alapú, fluid UX), ügyfél-regisztráció/belépés, members area (foglalások, lemondás, üzenetek), SSR+SEO.
**Demó:** végfelhasználó online foglal.

## M5 — Értesítések, üzenetek, realtime

Email sablonrendszer (tenant-szerkeszthető), visszaigazoló/módosító/lemondó emailek, 24 órás emlékeztető (ütemezett job), notifications_log, tenant↔ügyfél üzenetküldés, Reverb élő admin-értesítés + hangjelzés.
**Demó:** foglaláskor email megy ki és élőben felugrik az admin dashboardon.

## M6 — Forgalom-alapú jutalék, fizetés, számlázás

Fő scope (docs/10, SLO-63 epic): **forgalom-alapú jutalék-motor** (CommissionCalculator + ledger + havi újraszámolás), **havi jutalékszámlázás** (period-zárás, ÁFA, jutalékszámla-generálás) és **dunning** (emlékeztetők → türelmi idő → tenant felfüggesztés); **base-plan átállás** (háromlépcsős csomag eltávolítása, egyetlen ingyenes base plan) + plan limit érvényesítés UI-val. Opcionális, feature-flagelt **ügyfél-oldali fizetés** (Barion/Stripe `pending_payment` flow, webhook, refund) és **Számlázz.hu** tenant-számlázás (ezek a tenant jutalékrátáját 1,5%-ra emelik), egyedi subdomain/domain kezelés.

> A v1 online-fizetés-alapú application-fee modell visszavonva; nincs payment-facilitator jogi kapu (docs/10 §4) — a slot4u nem kezeli a foglalás ellenértékét, csak a saját havi jutalékát számlázza.

**Demó:** tenant forgalmat termel → a dashboard valós időben mutatja a felhalmozott jutalékot (ingyenes keret, plafon, effektív ráta) → hónapforduló után a slot4u havi jutalékszámlát állít ki, nemfizetésnél dunning indul.

## M7 — Admin dashboard & statisztika

Bento grid dashboard (mai bevétel élőben, legutóbbi foglalások, mini naptár), admin naptárnézet (nap/hét/dolgozó/helyiség szűrés, drag-and-drop módosítás), statisztika modul (ügyfélköltés, dolgozói aktivitás, kihasználtság), superadmin statisztikák (MRR, aktív tenantok).
**Demó:** a "startupper játszótere" kész.

> **A naptárnézet pozícionálása (SLO-44):** az események helyét a szerver **fali óra szerinti perc**ben adja meg (a saját tenant-lokális éjfelétől számítva), NEM eltelt percben. Ez tudatos: a naptár azt mutatja, amit az óra mutat a falon, ezért egy 10:00-s foglalás a 10:00-s soron van a tavaszi óraátállítás napján is, amikor éjfél óta csak 9 valós óra telt el. A teljes időzóna-konverzió a `BuildBookingCalendar`-ban történik, a frontend már csak percből pixelt számol. A rács ablaka alapból 06:00–22:00, de **soha nem takar el foglalást** — egy 05:00-s kezdés kitágítja. Éjfélen átnyúló foglalás a kezdőnapja aljára van vágva (a valós `ends_at` a kártyán marad). **A drag-and-drop átütemezés külön issue** — a meglévő `RescheduleBooking` action fölé épül, tehát az ütközésvizsgálat változatlan.

> **Kártya gyors-műveletek és gyors-foglalás (SLO-136):** a naptárkártya művelet-menüje és a foglalás-lista gyors-gombjai **ugyanabból a szabályból** dolgoznak (`resources/js/lib/bookingActions.ts`) és **ugyanarra a végpontra** postolnak — nincs második kódútvonal arra a kérdésre, hogy „mit lehet most ezzel a foglalással". A készlet: **jóváhagyás** (csak `requested`, `booking.approve` **ÉS** `feature_approval_flow` — a végpont ugyanez mögött van, docs/03), **teljesítés** (`no_time_slot`, docs/04 §1), **nem jelent meg**, **lemondás**; az **elutasítás indoklást követel** (docs/04 §5), ezért marad külön felületen. ⚠️ Ezzel a jóváhagyás kapott először UI-t: az SLO-26 óta létező `POST /bookings/{id}/approve` addig egyetlen képernyőről sem volt elérhető. **Átütemezés a naptáron a drag, nem menüpont.**
>
> **Gyors-foglalás üres sávra kattintva (SLO-136):** a `POST /bookings` végpontra megy (SLO-24), tehát az ütközésvizsgálat, a mód-szabályok és a `booking.create` a szerveren maradnak — a foglalt sáv **validációs hibaként** jön vissza, nem a felület tiltja. Csak **idősávos** szolgáltatás (`duration_based` / `resource_rental`) kínálható így: eseményhez esemény kell, ajánlatkérésnél pedig az ajánlat elfogadása az egyetlen út (docs/04 §6). ⚠️ **A záró időpontot a szerver vezeti le** a szolgáltatás hosszából, **valós eltelt időben** — a kliens fali óra szerinti perc-összeadása a tavaszi óraátállítás órájában nem létező időpontot adna (docs/01 §7); fix hossz nélküli (változó hosszú) szolgáltatásnál a záró időpont **kötelező**. Az ügyfél-választó **igény szerint (Inertia optional prop) töltődik** és a `CustomerVisibility` szabályára van kötve: employee csak a saját ügyfeleit látja benne, `customer.view` nélkül nincs választó — a dialog nem lehet ügyfél-lista szivárgás.

> **A statisztika modul definíciói (SLO-45 / SLO-137):** a `/reports` a `report.view` permission ÉS a `feature_reports` flag mögött van (docs/03 mátrix: tenant-admin és manager, employee NEM). ⚠️ **Az eredeti SLO-45 leírás „Közepes+ csomag" / „Alap csomagnál lockolt, upgrade CTA" kitétele ELAVULT** — a háromlépcsős csomag megszűnt (docs/10), egyetlen ingyenes `base` plan van, amin a `feature_reports` alapból BE van kapcsolva; a lekapcsolás superadmin per-tenant felülírás. **Bevétel = `confirmed + completed + no_show`** (docs/10 §3.1), pontosan mint az SLO-43 dashboard-csempén és a `/billing`-en — a három sosem mondhat mást. **Az összehasonlítási időszak alakkövető:** naptári preset (ez a hónap / előző hónap / ez az év) az előző hónapra ill. évre tolva (hónap-eleje-óta az előző hónap ugyanannyi napjához), gördülő és egyedi tartomány az azonos hosszúságú, közvetlenül megelőző időszakhoz — az oldal mindkét dátumtartományt kiírja, hogy a százalék alapja sose legyen találgatás. **Kihasználtság = lefoglalt perc / nyitvatartási perc**, a nevező a foglalási motor `WorkingWindows` szabályából (munkarend + kivételek); nyitvatartás nélküli erőforrásnál a mutató **`null` („nincs munkarend"), nem 0%** — a definiálatlan hányados nem tétlenséget jelent.

> **A superadmin platform-statisztika definíciói (SLO-45 / SLO-138):** a `/statistics` a superadmin panelen él (`ensure.superadmin` + `TenantPolicy::viewGlobalStatistics`), és a **jutalék-oldali** számok mellé (`/`, SLO-123/SLO-127) a platform saját üzletét teszi: tenant-életciklus, havi növekedés/lemorzsolódás, foglalási forgalom és forgalom-eloszlás. **A hónapok a PLATFORM időzónája szerinti naptári hónapok**, nem tenantonkéntiek — az egymást átfedő tenant-lokális hónapokból összerakott platform-összeg kétszer számolná a határnapot. ⚠️ **Churn = archiválás**, és az időbélyege a `tenants.deleted_at` (az archiválás soft-delete): nincs státusz-napló, ezért egy archivált tenant **visszaállítása visszamenőleg is eltünteti a churn-eseményét** a sorból — ezt az oldal ki is írja. A **felfüggesztés NEM churn** (docs/10 §6.6: nemfizető, akit a dunning visszahozhat). A churn nevezője a **hónap nyitó tenantszáma**, ezért egy hónapon belül belépő-és-kilépő tenant nem hígítja fel a rátát; nulla nevezőnél a ráta **`null` („nem értelmezett"), nem 0%** — ugyanaz az elv, mint a kihasználtságnál. A **headline churn a legutóbbi LEZÁRT hónapé**, mert a futó hónap szerkezetileg túl alacsony rátát mutatna. ⚠️ **A „csomag-eloszlás" AC ELAVULT** (nincs háromlépcsős csomag) — helyette **forgalom-sávok a jutalék-küszöb többszöröseiben** (≤1×, 1–2×, 2–5×, 5× felett); a küszöbön PONTOSAN álló tenant a „még nem fizet" sávba esik, ahogy a jutalékmodell is kezeli (docs/10 §2.3).

> **A dashboard „mai bevétel" definíciója (SLO-43):** a `confirmed` + `completed` + `no_show` státuszú mai foglalások `price_minor` összege — szándékosan **ugyanaz az alap, amire a jutalék számol** (docs/10 §3.1), hogy a vezérlőpult soha ne mondjon mást, mint a tenant `/billing` oldala. A „mai nap" határa a **tenant fali órája** (docs/01 §7), nem az UTC nap. Az `no_time_slot` módú, kezdőidő nélküli foglalás a létrehozása napjára esik. A **kihasználtság nem itt van**, hanem a statisztika modulban (SLO-45) — ahhoz munkarend-alapú kapacitás kell.

## M8 — Hardening, üzemeltetés, launch

Biztonsági átvilágítás (tenant-izoláció tesztek!), GDPR (adatexport, törlés, anonimizálás), rate limiting, backup+restore teszt, monitoring/alerting, teljesítmény (N+1, indexek), staging→prod deploy pipeline, marketing landing (slot4u.hu, lajhár branding), dokumentáció.
**Demó:** éles indulás.

## Későbbi fázisok (NEM MVP — backlog)

SMS (feature_sms), publikus API (feature_api), dokumentumtár, AI természetes nyelvű foglalás (feature_nlp_booking), Google Meet/Calendar integráció, többnyelvű UI (en), mobilapp.
