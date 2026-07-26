# 02 — Adatmodell (MVP)

Minden tenant-tulajdonú táblán: `tenant_id` (indexelt, FK), `created_at/updated_at`, soft delete ahol értelme van (`deleted_at`). Pénz: `*_minor` integer + `currency`. Idő: UTC datetime.

**Scope:** ez a dokumentum KIZÁRÓLAG az MVP sémát tartalmazza. A Phase 2 modulok (bérlet/csomag, membership, custom fields, form builder) sémája: `07-phase2-modulok.md` — azok a táblák az MVP migrációkba NEM kerülnek be.

## Tenant & előfizetés

> **Monetizáció:** a slot4u **forgalom-alapú jutalékkal** (havi jutalékszámla) monetizál, NEM fix havidíjas háromlépcsős csomaggal. Igazság-forrás: `10-arazasi-modell-jutalek.md`. A `plans`/`plan_limits`/`plan_features` táblák megmaradnak, de **egyetlen `base` plan**-t írnak le nagyvonalú limitekkel (a basic/mid/max **megszűnt**). A jutalék-séma a „Forgalom-alapú jutalék" szekcióban.

```
tenants            id, name, slug(subdomain), status(trial|active|suspended|archived),
                   timezone, locale, branding(json: logó, színek), settings(json)
tenant_domains     id, tenant_id, domain, is_primary, verified_at        -- egyedi domain (feature_custom_domain)
plans              id, code(base), name, monthly_price_minor(=0), currency, is_active
                   -- egyetlen ingyenes base plan; a háromlépcsős basic/mid/max megszűnt (docs/10 §5.6)
plan_limits        id, plan_id, key(max_admins|max_employees|max_customers|max_locations|...), value
plan_features      plan_id, feature_code                                 -- mely feature jár a base plannel
subscriptions      id, tenant_id, plan_id, status(trialing|active|past_due|canceled),
                   trial_ends_at, current_period_end, canceled_at
                   -- státusz-jelző; a past_due mostantól a JUTALÉKSZÁMLA nemfizetését tükrözi (docs/10 §6.6),
                   -- nem recurring havidíj-bukást
tenant_features    tenant_id, feature_code, enabled, overridden_by       -- superadmin felülírás
```

## Felhasználók & jogosultság (spatie/laravel-permission, teams=tenant_id)

```
users              id, tenant_id(nullable — superadminnál NULL), name, email, phone,
                   password, locale, last_login_at
-- spatie táblák: roles(tenant scoped), permissions(globális kódok), model_has_roles,
--                role_has_permissions, model_has_permissions(egyedi user-felülírás)
```

Alap szerepkörök (tenant létrehozáskor seedelve): `tenant-admin`, `manager`, `employee`, `customer`. Superadmin: globális `super-admin` role, tenant_id nélkül. Permission kódok: `booking.view|create|edit|delete`, `customer.*`, `service.*`, `employee.*`, `billing.*`, `report.view`, `settings.edit`, `message.send` — teljes mátrix: `03-jogosultsagok.md`.

## Törzsadatok

```
locations          id, tenant_id, name, address(json), phone, sort_order, active
rooms              id, tenant_id, location_id, name, type(room|equipment), capacity, description, active
staff              id, tenant_id, user_id(nullable), name, title, bio, photo, color, active
                   -- dolgozó ≠ user kötelezően: lehet naptár-erőforrás login nélkül
staff_locations    staff_id, location_id        -- egy dolgozó több telephelyen (SLO-51)
service_categories id, tenant_id, name, sort_order
services           id, tenant_id, category_id, name, description, booking_mode(enum),
                   duration_minutes, buffer_before/after_minutes, price_minor, currency,
                   capacity, requires_staff, requires_room, requires_approval,
                   waitlist_enabled, online_payment_required, settings(json), active
service_staff      service_id, staff_id          -- ki nyújthatja
service_rooms      service_id, room_id           -- hol nyújtható
```

**Több telephely szabályai (SLO-51):** egy dolgozó több helyszínhez rendelhető; elérhetősége helyszínenként eltérhet (`schedules.location_id`); foglaláskor csak a kiválasztott helyszín sávjai jelennek meg; ugyanaz a dolgozó nem foglalható két helyszínen átfedő időben.

## Elérhetőség (availability)

```
schedules          id, tenant_id, schedulable_type/id (staff|room), location_id(nullable),
                   day_of_week, start_time, end_time, valid_from, valid_until
schedule_exceptions id, tenant_id, schedulable_type/id, date, start/end_time(nullable=egész nap),
                   type(off|extra), note     -- szabadság, ünnep, extra nyitás
events             id, tenant_id, service_id, staff_id, room_id, starts_at, ends_at,
                   capacity, booked_count, waitlist_enabled, status, recurrence_rule(nullable),
                   series_id(nullable, uuid)   -- a 3-as (eseményalapú) módhoz: meghirdetett alkalmak
                   -- series_id: heti sorozat generált alkalmait csoportosítja (SLO-20)
```

## Foglalások

```
bookings           id, tenant_id, code(publikus azonosító), customer_id(users, nullable),
                   guest_name/guest_email/guest_phone (nullable — vendég-foglalás fiók
                   nélkül, SLO-128; ügyfélhez kötött foglalásnál mindhárom NULL, a fiók
                   az igazság forrása. Index: (tenant_id, guest_email)),
                   service_id, booking_mode(snapshot), staff_id, room_id, event_id(nullable),
                   starts_at, ends_at (nullable időpont nélküli módnál),
                   status(enum), party_size, price_minor, currency, notes,
                   source(online|admin), canceled_at, cancel_reason, approved_by, approved_at,
                   hold_expires_at(nullable), reject_reason(nullable),
                   rescheduled_from_id(nullable, self-FK: melyik foglalást váltja fel — az
                   átütemezés lemondás+új foglalás pár, ez köti össze a kettőt; a
                   `booking_modified` értesítés is ezen dől el, SLO-109)
booking_status_history id, booking_id, from, to, actor_id, created_at
waitlist_entries   id, tenant_id, event_id|service_id, customer_id, position,
                   status(waiting|offered|converted|expired), offered_until
quote_requests     id, tenant_id, service_id, customer_id(nullable),
                   guest_name/guest_email/guest_phone (nullable — mint a bookings-nál,
                   SLO-128), parameters(json), status
                   (new|in_progress|quoted|accepted|rejected), price_minor(nullable),
                   currency(nullable), valid_until(nullable), internal_notes(nullable),
                   booking_id(nullable, az accepted-kor generált foglalás)
quote_request_messages id, tenant_id, quote_request_id, user_id(nullable), body
```

**Implementáció (SLO-27):** a `quote_requests` a fenti oszlopokkal jött létre (a korábbi `quoted_price_minor`/`admin_notes` felváltva `price_minor`+`currency`+`valid_until` ajánlat-mezőkre és `internal_notes` admin-jegyzetre; `booking_id` az accepted-kor generált foglalásra mutat). Az ajánlaton belüli üzenetváltás egy dedikált `quote_request_messages` táblát kap (author `user_id`+`body`), NEM a lenti generikus `messages` táblát — az (`sender_id`/`recipient_id`/`read_at`, `feature_messages`) tágabb M5-koncepció, a quote-beszélgetésnek csak rendezett szerző/törzs napló kell.

`BookingStatus` enum: `requested → approved → confirmed → completed | canceled | rejected | no_show` (+`pending_payment`). Módonkénti állapotgráf: `04-foglalasi-modok.md`.

**Ütközésvédelem:** foglaláskor `SELECT ... FOR UPDATE` az érintett staff/room idősávjára tranzakcióban + alkalmazás-szintű ütközésvizsgálat. Event kapacitás: atomi `UPDATE events SET booked_count = booked_count+1 WHERE booked_count < capacity`.

## Forgalom-alapú jutalék (docs/10, M6)

A slot4u bevételi modellje. Teljes spec és üzleti szabályok: `10-arazasi-modell-jutalek.md`. Minden összeg `*_minor` int + `currency`, minden ráta `*_bps` int (nincs float). A `commission_settings` platform-szintű (tenant_id NÉLKÜL, superadmin), a többi tenant-tulajdonú (`BelongsToTenant`).

```
commission_settings          id, free_threshold_minor, rate_bps, rate_with_integration_bps,
                             monthly_cap_minor(nullable), currency, effective_from, created_by, created_at
                             -- platform-default, VERZIÓZOTT (új sor = új konfig); a régit nem írjuk felül
tenant_commission_overrides  tenant_id(PK,FK), free_threshold_minor(nullable), rate_bps(nullable),
                             rate_with_integration_bps(nullable), monthly_cap_minor(nullable),
                             note, overridden_by, updated_at      -- null mező = öröklés a settings-ből
booking_commission_items     id, tenant_id, booking_id(FK, unique), period(YYYY-MM, idx),
                             amount_minor(snapshot listaár), rate_bps(snapshot), realized_at,
                             state(billable|removed), settings_id(FK), currency, timestamps
                             -- ledger / forrás-igazság: minden jutalékköteles foglalásra egy sor
commission_corrections       id, tenant_id, type(booking_adjustment|carry_over), booking_id(nullable FK),
                             source_period(YYYY-MM, a korrigált LEZÁRT hónap), period(YYYY-MM, ahova a
                             jóváírás kerül), corrected_amount_minor(nullable), corrected_state(nullable),
                             commission_delta_minor(előjeles, <= 0), currency, timestamps
                             -- index(tenant_id, period) + index(tenant_id, source_period)
                             -- lezárt period utólagos változásának jóváírása (docs/10 §8.2)
tenant_billing_periods       id, tenant_id, period(YYYY-MM), turnover_minor, commission_minor,
                             correction_minor(<= 0, a period commission_corrections összege),
                             cap_reached(bool), status(open|invoiced|paid|overdue|void),
                             invoice_id(nullable FK), recomputed_at, updated_at  -- unique(tenant_id, period)
                             -- DERIVÁLT cache a booking_commission_items-ből újraszámolva
commission_invoices          id, tenant_id, period(YYYY-MM, unique a tenanton belül), turnover_minor,
                             billable_base_minor, correction_minor, commission_net_minor, vat_bps, vat_minor,
                             total_gross_minor, currency, status(draft|issued|paid|overdue|void),
                             issued_at, due_at, paid_at, paid_method(nullable),
                             provider(nullable), provider_ref(nullable), pdf_path(nullable), created_at
                             -- slot4u → tenant havi jutalékszámla (saját, ÁFA-s SaaS-bevétel)
```

## Ügyfél-oldali fizetés & számlázás (opcionális, M6)

> Ez a tenant **saját** ügyfél-fizetése (Barion/Stripe a tenant javára) és a tenant ügyfél-számlázása — **független** a slot4u jutalék-beszedésétől (docs/10 §4). A `feature_online_payment` / `feature_invoicing` aktiválása a tenant jutalékrátáját 1,0%-ról 1,5%-ra emeli (docs/10 §2.1).

```
payments           id, tenant_id, booking_id, provider(sandbox|barion|stripe), provider_ref,
                   amount_minor, currency, status(pending|paid|failed|refunded), paid_at, payload(json)
                   -- egy sor / checkout-kísérlet (SLO-130); unique(provider, provider_ref) = a
                   -- webhook idempotencia-kulcsa; `sandbox` = beépített teszt-gateway (nem prod)
refunds            id, tenant_id, payment_id, amount_minor, currency, status(pending|completed|failed),
                   reason, provider_ref, refunded_at
                   -- teljes/részleges/manuális refund (SLO-131). Előbb SZÁNDÉK (`pending`) a
                   -- lemondás tranzakciójában, a gateway-hívást a ProcessRefund job zárja le →
                   -- gateway-kiesésnél auditálható tartozás marad. Több részleges refund
                   -- állhat egy payment-en; az összegük a fizetett összegre van vágva.
                   -- A payment `refunded` csak TELJES visszatérítésnél lesz.
invoices           id, tenant_id, booking_id, provider(szamlazzhu), provider_ref, number,
                   status(issued|storno|failed), pdf_path, issued_at
```

## Integrációs naplózás (M6-tól, minden külső hívásra)

```
integration_logs   id, tenant_id, provider, operation, request(maszkolt), response(maszkolt),
                   status, created_at        -- Stripe/Barion, Számlázz.hu, később calendar/marketing
webhook_logs       id, tenant_id, provider, event_name, payload, processed, processed_at
```

Szenzitív adat (kártya, API kulcs) maszkolva; retention 90 nap. Részletek: `06-integraciok-es-api.md`.

## Kommunikáció & egyéb

```
message_templates  id, tenant_id, key(booking_confirmed|booking_modified|booking_canceled|
                   booking_rejected|waitlist_offer|quote_ready|
                   reminder_24h|payment_success|payment_failed), channel(email|sms),
                   locale, subject, body, enabled
messages           id, tenant_id, sender_id, recipient_id, booking_id(nullable), body, read_at
notifications_log  id, tenant_id, type, channel, recipient, status(pending|sent|failed),
                   dedupe_key(nullable), sent_at, error, timestamps
                   — type: a message_templates key-ekkel azonos halmaz (NotificationType enum)
                   — unique(tenant_id, type, dedupe_key): idempotencia-kulcs, egy
                   értesítés (pl. booking:{id}, booking:{id}:reminder_24h,
                   waitlist_entry:{id}, quote_request:{id}) legfeljebb egyszer megy ki
                   (SLO-108/SLO-109)
audit_logs         id, tenant_id(nullable), user_id(nullable), action, auditable_type/id(nullable), old_values/new_values(json), ip_address, created_at(immutable, nincs updated_at)
```

## Statisztika

MVP: lekérdezés-alapú riportok indexelt oszlopokon (bookings.starts_at, status, staff_id, service_id) + napi aggregáló job egy `daily_stats` táblába (tenant_id, date, bookings_count, revenue_minor, new_customers). Külön BI nem kell.

## Phase 2 modulok (NEM MVP)

Bérlet- és csomagkezelés (packages, customer_packages, package_usage — SLO-58), membership rendszer (membership_plans, customer_memberships — SLO-59), egyedi ügyfélmezők és form builder (custom_fields, forms, form_submissions — SLO-60): teljes séma és üzleti szabályok a `07-phase2-modulok.md`-ben.
