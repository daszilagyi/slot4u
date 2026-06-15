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

## M6 — Előfizetés, fizetés, számlázás (Max funkciók)

Csomagválasztás+trial flow, tenant előfizetés-fizetés (Barion/Stripe recurring), plan limit érvényesítés UI-val, ügyfél-oldali online fizetés foglaláskor (pending_payment flow, webhook, refund-jelzés), Számlázz.hu integráció, egyedi subdomain/domain kezelés.
**Demó:** Max csomagos tenant ügyfele fizet és számlát kap.

## M7 — Admin dashboard & statisztika

Bento grid dashboard (mai bevétel élőben, legutóbbi foglalások, mini naptár), admin naptárnézet (nap/hét/dolgozó/helyiség szűrés, drag-and-drop módosítás), statisztika modul (ügyfélköltés, dolgozói aktivitás, kihasználtság), superadmin statisztikák (MRR, aktív tenantok).
**Demó:** a "startupper játszótere" kész.

## M8 — Hardening, üzemeltetés, launch

Biztonsági átvilágítás (tenant-izoláció tesztek!), GDPR (adatexport, törlés, anonimizálás), rate limiting, backup+restore teszt, monitoring/alerting, teljesítmény (N+1, indexek), staging→prod deploy pipeline, marketing landing (slot4u.hu, lajhár branding), dokumentáció.
**Demó:** éles indulás.

## Későbbi fázisok (NEM MVP — backlog)

SMS (feature_sms), publikus API (feature_api), dokumentumtár, AI természetes nyelvű foglalás (feature_nlp_booking), Google Meet/Calendar integráció, többnyelvű UI (en), mobilapp.
