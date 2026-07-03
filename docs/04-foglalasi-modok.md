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

## 3. `event_based` — Meghirdetett esemény

Példa: csoportos jóga, workshop, webinár.
- Admin eseményt hirdet (`events`): fix kezdés/vég, kapacitás, opcionális ismétlődés (RRULE-szerű: heti/napi, végdátumig — MVP-ben heti ismétlés elég).
- Foglalás = jelentkezés, `party_size` támogatás, atomi kapacitás-csökkentés.
- **Várólista** (ha `waitlist_enabled` + feature): kapacitás betelte után FIFO várólista; lemondáskor az első várakozó automatikus értesítést és X óra foglalási ablakot kap (`offered_until`), lejáratkor a következő jön. Job kezeli.
- Esemény törlésekor: minden jelentkező értesítése + (ha `feature_online_payment` aktív) automatikus refund-jelzés.

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
