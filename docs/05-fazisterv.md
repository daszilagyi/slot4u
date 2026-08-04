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

> **A statisztika modul definíciói (SLO-45 / SLO-137):** a `/reports` a `report.view` permission ÉS a `feature_reports` flag mögött van (docs/03 mátrix: tenant-admin és manager, employee NEM). ⚠️ **Az eredeti SLO-45 leírás „Közepes+ csomag" / „Alap csomagnál lockolt, upgrade CTA" kitétele ELAVULT** — a háromlépcsős csomag megszűnt (docs/10), egyetlen ingyenes `base` plan van, amin a `feature_reports` alapból BE van kapcsolva; a lekapcsolás superadmin per-tenant felülírás. **Bevétel = `confirmed + completed + no_show`** (docs/10 §3.1), pontosan mint az SLO-43 dashboard-csempén és a `/billing`-en — a három sosem mondhat mást. **Az összehasonlítási időszak alakkövető:** naptári preset (ez a hónap / előző hónap / ez az év) az előző hónapra ill. évre tolva (hónap-eleje-óta az előző hónap ugyanannyi napjához), gördülő és egyedi tartomány az azonos hosszúságú, közvetlenül megelőző időszakhoz — az oldal mindkét dátumtartományt kiírja, hogy a százalék alapja sose legyen találgatás. **Kihasználtság = lefoglalt perc / nyitvatartási perc**, a nevező a foglalási motor `WorkingWindows` szabályából (munkarend + kivételek); nyitvatartás nélküli erőforrásnál a mutató **`null` („nincs munkarend"), nem 0%** — a definiálatlan hányados nem tétlenséget jelent.

> **A dashboard „mai bevétel" definíciója (SLO-43):** a `confirmed` + `completed` + `no_show` státuszú mai foglalások `price_minor` összege — szándékosan **ugyanaz az alap, amire a jutalék számol** (docs/10 §3.1), hogy a vezérlőpult soha ne mondjon mást, mint a tenant `/billing` oldala. A „mai nap" határa a **tenant fali órája** (docs/01 §7), nem az UTC nap. Az `no_time_slot` módú, kezdőidő nélküli foglalás a létrehozása napjára esik. A **kihasználtság nem itt van**, hanem a statisztika modulban (SLO-45) — ahhoz munkarend-alapú kapacitás kell.

## M8 — Hardening, üzemeltetés, launch

Biztonsági átvilágítás (tenant-izoláció tesztek!), GDPR (adatexport, törlés, anonimizálás), rate limiting, backup+restore teszt, monitoring/alerting, teljesítmény (N+1, indexek), staging→prod deploy pipeline, marketing landing (slot4u.hu, lajhár branding), dokumentáció.
**Demó:** éles indulás.

## Későbbi fázisok (NEM MVP — backlog)

SMS (feature_sms), publikus API (feature_api), dokumentumtár, AI természetes nyelvű foglalás (feature_nlp_booking), Google Meet/Calendar integráció, többnyelvű UI (en), mobilapp.
