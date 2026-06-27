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
