# 04 — A 6 foglalási mód üzleti szabályai

Egységes `bookings` tábla, `booking_mode` diszkriminátor, Strategy pattern:
`app/Services/Booking/Modes/{DurationBased,EventBased,ResourceRental,NoTimeSlot,ManualApproval,QuoteRequest}Strategy.php` — közös interfész: `availability()`, `validate()`, `create()`, `transitions()`.

## 1. `no_time_slot` — Időpont nélküli szolgáltatás

Példa: videó/digitális termék vásárlás, receptkérés, dokumentumbeküldés.
- Nincs starts_at/ends_at. Staff opcionális, fizetés opcionális.
- `fulfillment_type`: digital (azonnali link) / manual (admin teljesíti) / downloadable.
- Állapot: `confirmed → completed` (manualnál admin zárja le).

## 2. `duration_based` — Idősávos foglalás (CORE, legfontosabb)

Példa: 60 perc masszázs, 50 perc konzultáció, edzés.
- Elérhetőség = staff (és/vagy room) munkarendje (`schedules`) − kivételek (`schedule_exceptions`) − meglévő foglalások − buffer idők.
- Slot-generálás: szolgáltatás `duration + buffer` rácson, tenant beállítható rácsköz (15/30 perc).
- Ügyfél választhat: szolgáltatás → (opció: dolgozó VAGY "bárki") → szabad időpont.
- Ütközésvédelem: tranzakció + sávzár (lásd 02). Két párhuzamos foglalás közül pontosan egy nyerhet.
- Lemondási szabály: tenant-beállítás (pl. 24 órán belül nem mondható le online).
- Módosítás = lemondás + új foglalás egy tranzakcióban, history-val.

**CreateBooking (SLO-24):** a `App\Actions\Booking\CreateBooking` az egyetlen foglalás-létrehozó, **race-condition-biztos** (docs/02 ütközésvédelem): idősávos módoknál (duration_based/resource_rental) tranzakción belül **`lockForUpdate`** az érintett staff/room soron + ismételt átfedés-vizsgálat a lock alatt (két konkurens kérés ugyanarra a sávra → pontosan egy nyer); `event_based`-nél **atomi** `UPDATE events SET booked_count = booked_count + party_size WHERE booked_count + party_size <= capacity` (0 érintett sor = betelt); `no_time_slot`-nál közvetlen létrehozás. A kezdő státusz a service-konfigból: `requires_approval` → `requested`, `online_payment_required` → `pending_payment`, egyébként `confirmed`; **admin** forrás (source=admin) átugorja a jóváhagyás/fizetés kaput → `confirmed`. Ütközéskor **`SlotUnavailableException`** (i18n, 422 render). Az ár/mód **snapshot**-olva a foglaláson (a `price_minor` **foglalásonkénti** ár, nem fejenkénti — `event_based`-nél a `party_size` a kapacitást fogyasztja, az árat nem szorozza; a per-fő árazás Phase 2). **`event_based` lemondás** (SLO-25 óta) atomikusan **visszaadja** az `events.booked_count`-ot: amint egy event-foglalás kilép a helyet-foglaló státuszokból (`canceled`/`rejected`/`no_show`), a `ChangeBookingStatus` visszaírja a `party_size`-t (`booked_count >= party_size` alsó korláttal) és felajánlja a felszabadult helyet a várólista következő tagjának. A `RescheduleBooking` MVP-ben továbbra is csak idősávos módokra ajánlott. **Átfoglalás** (`RescheduleBooking`) = lemondás + új foglalás egy tranzakcióban (ha az új sáv foglalt, a teljes művelet rollbackel, az eredeti megmarad). Admin belépési pont: `POST /bookings` (`booking.create`), a FormRequest FK-jai tenant-scope-olt `exists`-szel + tenant-tz→UTC konverzió. A publikus foglalási flow M4 (SLO-32), az event várólista SLO-25.

**AvailabilityService (SLO-22):** az `App\Services\Booking\AvailabilityService::slotsForDay(service, date, ?staffId, ?roomId)` (+`slotsForRange`) a `duration_based`/`resource_rental` szabad slotjait számolja: a `schedules` sávjai (weekday + érvényességi ablak) **− `schedule_exceptions`** (off kivon, extra hozzáad) **− meglévő foglalások** (blokkoló státuszok: minden a canceled/rejected/no_show kivételével) **− bufferek**, a tenant `slot_interval_minutes` (15/30) rácsán, a szolgáltatás `duration`-jével. A **buffer csak foglalások közt** számít (a nyitás szélén átlóghat — a nyitó slot elérhető marad). „Bárki" dolgozó = a hozzárendelt staff slotjainak **uniója** (kezdésenként deduplikálva); adott `staffId`/`roomId` szűkít — a kért staff/room-ot a service **saját** erőforrásaira validálja (idegen id → üres). Adott `locationId`-nél csak az adott helyszínhez (vagy helyszín-függetlenül `null`-hoz) kötött munkarend-sávok számítanak (docs/02 §54, SLO-51). Minden query **explicit `tenant_id`-horgonyzású** (nem csak az ambient scope-ra bízva). **Időzóna:** a számítás a **tenant TZ-ben** (fali-óra rács, DST-helyes), a `Slot` **UTC** instantokat ad vissza (docs/01 §7). N+1-mentes: minden erőforrásra bulk `whereIn` (schedule/exception/booking). `resource_rental` szabad időtartamnál `isDurationAllowed()` a `settings.min/max_duration_minutes`-t ellenőrzi. **Megjegyzés:** a staff+room együttes metszet MVP-ben csak explicit `roomId` esetén; a foglalás-idejű atomi ütközésvédelem az SLO-24.

## 3. `event_based` — Meghirdetett esemény

Példa: csoportos jóga, workshop, webinár.
- Admin eseményt hirdet (`events`): fix kezdés/vég, kapacitás, opcionális ismétlődés (RRULE-szerű: heti/napi, végdátumig — MVP-ben heti ismétlés elég).
- Foglalás = jelentkezés, `party_size` támogatás, atomi kapacitás-csökkentés.
- **Várólista** (ha `waitlist_enabled` + feature): kapacitás betelte után FIFO várólista (`waitlist_entries`, SLO-25). A `JoinWaitlist` action a betelt eseményre `waiting` bejegyzést tesz (esemény-soronkénti lock → hézagmentes `position`). Lemondáskor felszabaduló hely az első várakozónak `offered` státuszt + X óra ablakot ad (`offered_until`, tenant-beállítás `waitlist_offer_hours`, default 24), `WaitlistOffered` eventtel (értesítés M5). Az ajánlat **eseményenként szigorúan soros** (egyszerre egy `offered`), és csak akkor születik, ha a felszabadult kapacitás **elég a soron következő várakozó `party_size`-jához** (`booked_count + party_size <= capacity`); ha a lista élén álló party nem fér be, a sor **vár** a további helyekre — nem ugorjuk át egy mögötte álló kisebb partyval (a best-fit párhuzamos ajánlás Phase-2). A `waitlist:expire-offers` óránkénti job lejárt ajánlatot `expired`-re állít és a következőnek ajánl; ha a várakozó lefoglal, a bejegyzése `converted`. A felszabadult hely „lágy" foglalás: a kapacitás forrása a `booked_count`, az ajánlat előny, de a végső foglalás az atomi kapacitás-claim.
- Esemény törlésekor: minden jelentkező értesítése + (ha `feature_online_payment` aktív) automatikus refund-jelzés.

**Admin meghirdetés (SLO-20):** az `/events` oldal (`schedule.manage`, Tenant Admin + Manager — az események meghirdetett alkalmak = operatív scheduling) kezeli az eseményeket **kizárólag `event_based` szolgáltatásokhoz** (`EventRequest` validál). A `starts_at`/`ends_at` a datetime-local inputból a **tenant időzónája szerint** értelmezve, UTC-ben tárolva (docs/01 §7), tenant-tz-ben megjelenítve. **Heti ismétlődés:** `CreateEvent` egy alkalmat generál hetente a végdátumig, közös `series_id`-vel (max 260 alkalom); szerkesztés/lemondás/törlés „csak ez" vagy „ez és a következők" scope-pal (`UpdateEvent`/`CancelEvent`/`DeleteEvent` — a „következők" a sorozat késői, `starts_at >` alkalmaira propagál, a nem-temporális mezőkre; a kapacitás csak ott, ahol nem esne a `booked_count` alá). **Ütközésvizsgálat:** azonos staff **vagy** room átfedő idejű, `scheduled` eseménye elutasítva (`starts_at` hiba) — meghirdetéskor; a sorozat késői alkalmaira és a foglalás-idejű ütközésre az M3 availability engine finomít. **Kapacitás-védelem:** a kapacitás nem csökkenthető a `booked_count` alá (docs/04 edge case). Jelentkezőkkel bíró esemény **nem törölhető** (hard delete tiltott) — le kell mondani (`status=canceled`, jelentkező-értesítés M5). A jelentkezés/várólista/atomi kapacitás-csökkentés maga a foglalási motorral (M3, SLO-25) jön.

## 4. `resource_rental` — Erőforrás-foglalás

Példa: teremfoglalás, pálya, szauna, eszközbérlés.
- Nem staffot, hanem roomot/eszközt foglal az ügyfél. `resource = room` (MVP-ben az eszközt is room-rekordként kezeljük `type` mezővel — külön equipment tábla NEM kell MVP-be).
- Időtartam: fix vagy szabad (min/max korlátokkal, `settings` json-ban).
- Opcionális kaució (`deposit_minor`) — online előleg, ha `feature_online_payment` aktív.
- Elérhetőség: room nyitvatartása − foglalások; ütközésvédelem ugyanaz, mint 2-nél.

## 5. `manual_approval` — Jóváhagyáshoz kötött foglalás

Példa: orvosi konzultáció előszűréssel, nagy értékű szolgáltatás.
- Bármely fenti mód kombinálható `requires_approval = true`-val (NEM önálló elérhetőség-logika!).
- Flow: ügyfél kér → `requested` (a sáv "soft hold"-ot kap, tenant-beállítás szerint X óráig) → admin: `approved` (→ confirmed / pending_payment) vagy `rejected` (indoklással) vagy módosított időpontot ajánl.
- Lejárt soft hold automatikusan felszabadul (job).

## 6. `quote_request` — Ajánlatkérés alapú

Példa: rendezvény, catering, komplex csomag.
- Nem azonnali foglalás: ügyfél űrlapot tölt ki (szolgáltatásonként definiálható mezők, `parameters` json), `quote_requests` rekord jön létre.
- Admin flow: `new → in_progress → quoted` (ár + érvényesség) `→ accepted` (ekkor opcionálisan booking generálódik) `| rejected`.
- Üzenetváltás a kérelmen belül (messages, booking_id helyett quote_request_id kapcsolattal).

## Szolgáltatás-törzsadat implementáció (SLO-18, M2)

A `BookingMode` enum **öt** értéket tartalmaz (`no_time_slot`, `duration_based`, `event_based`, `resource_rental`, `quote_request`) — a `manual_approval` **nem** önálló diszkriminátor-érték, hanem a `requires_approval` boolean flag bármely fenti módra rétegezve (lásd §5). A hatodik „mód" tehát flag, nem enum-case.

A `services` tábla mód-specifikus oszlopai (`duration_minutes`, `capacity`, `buffer_*`, `waitlist_enabled`) csak a saját módjuknál értelmezettek; a `ServiceRequest` mód-függően validál (pl. `duration_based`→duration kötelező, `event_based`→capacity kötelező), a `CreateService`/`UpdateService` action pedig a `NormalizesServiceData` traiten át kinullázza a mód-idegen mezőket, így egy szolgáltatás sosem hordoz elavult adatot módváltás után. A szabad-formátumú, mód-függő beállítások a `settings` json-ban élnek (`fulfillment_type` / `min_duration_minutes`+`max_duration_minutes`+`deposit_minor` / `quote_fields`).

**Feature-függés** (docs/03): a `quote_request` mód a `feature_quote_request`, a `waitlist_enabled` a `feature_waitlist` (és csak `event_based`), a `requires_approval` a `feature_approval_flow`, az `online_payment_required` a `feature_online_payment` engedélyezését igényli — a `ServiceRequest` `withValidator` ága 422-vel utasít el, ha a feature ki van kapcsolva. A `service_staff`/`service_rooms` pivotok a szolgáltatás nyújtóit/helyszíneit kötik; a hozzárendelt id-k tenant-scope-olt `exists` szabállyal validáltak. Ütközésvédelem, slot-generálás és a booking-strategyk M3-ban jönnek.

## Közös állapotgép

```
requested ──approve──▶ approved ──(fizetés kell?)──▶ pending_payment ──paid──▶ confirmed
    │                                                      │ timeout/failed
    └──reject──▶ rejected                                  └──▶ canceled
confirmed ──▶ completed | canceled | no_show
```
Sima (nem jóváhagyásos) foglalás `confirmed`-ön (vagy `pending_payment`-en) indul. Minden átmenet: `booking_status_history` + event (értesítések, Reverb).

**Implementáció (SLO-23, M3):** a `BookingStatus` enum tartja az átmenet-mátrixot (`allowedTransitions()`/`canTransitionTo()`/`isTerminal()`) — ez az egyetlen igazság-forrás. A `ChangeBookingStatus` action az egyetlen szentesített státuszváltó: érvénytelen átmenetnél `InvalidBookingTransitionException`-t dob, minden átmenetet `booking_status_history`-ba ír (from/to/actor), rögzíti a mellékhatásokat (`approved_by`/`approved_at` jóváhagyáskor, `canceled_at`/`cancel_reason` lemondáskor), és `BookingStatusChanged` (+ lemondáskor `BookingCanceled`) domain eventet dob. Létrehozáskor a `Booking` model `null → kezdő státusz` history-t ír és `BookingCreated`-et dob; a listenerek M5-ben kapcsolódnak. **Lemondási szabály:** a `CancelBooking` action az **online** (ügyfél) lemondást elutasítja a tenant `cancellation_deadline_hours` (docs/02 §Beállítások, SLO-21) ablakán belül; admin bármikor lemondhat. **Publikus kód:** a `Booking` létrehozáskor egyedi, nem találgatható 8 karakteres kódot kap. Idő UTC-ben (docs/01 §7). A `BookingModeStrategy` mód-specifikus rétege (availability/create) az azt implementáló issue-kkal jön (SLO-22 availability, SLO-24 create) — az állapotgép közös és mód-független.

## Edge case-ek (tesztelendő!)

- Párhuzamos foglalás ugyanarra a slotra (race condition) — pontosan 1 sikeres
- DST átállás napján slot-generálás
- Staff munkarend-módosítás meglévő jövőbeli foglalásokkal (figyelmeztetés, nem törlés)
- Esemény kapacitás-csökkentés meglévő jelentkezők alá — tiltott
- Várólista: lemondás, ablak lejárta, többszörös felajánlás
- Lemondási határidő pontosan a határon
- Tenant timezone ≠ ügyfél timezone (megjelenítés mindig tenant TZ, jelölve)
- Suspended tenant publikus oldala foglalást nem fogad
- Buffer idők átlógása nyitvatartás szélén
