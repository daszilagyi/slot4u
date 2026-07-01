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
                   capacity, booked_count, waitlist_enabled, status, recurrence_rule(nullable)
                   -- a 3-as (eseményalapú) módhoz: meghirdetett alkalmak
```

## Foglalások

```
bookings           id, tenant_id, code(publikus azonosító), customer_id(users),
                   service_id, booking_mode(snapshot), staff_id, room_id, event_id(nullable),
                   starts_at, ends_at (nullable időpont nélküli módnál),
                   status(enum), party_size, price_minor, currency, notes,
                   source(online|admin), canceled_at, cancel_reason, approved_by, approved_at
booking_status_history id, booking_id, from, to, actor_id, created_at
waitlist_entries   id, tenant_id, event_id|service_id, customer_id, position,
                   status(waiting|offered|converted|expired), offered_until
quote_requests     id, tenant_id, service_id, customer_id, parameters(json), status
                   (new|in_progress|quoted|accepted|rejected), quoted_price_minor, admin_notes
```

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
tenant_billing_periods       id, tenant_id, period(YYYY-MM), turnover_minor, commission_minor,
                             cap_reached(bool), status(open|invoiced|paid|overdue|void),
                             invoice_id(nullable FK), recomputed_at, updated_at  -- unique(tenant_id, period)
                             -- DERIVÁLT cache a booking_commission_items-ből újraszámolva
commission_invoices          id, tenant_id, period(YYYY-MM, unique a tenanton belül), turnover_minor,
                             billable_base_minor, commission_net_minor, vat_bps, vat_minor,
                             total_gross_minor, currency, status(draft|issued|paid|overdue|void),
                             issued_at, due_at, paid_at, paid_method(nullable),
                             provider(nullable), provider_ref(nullable), pdf_path(nullable), created_at
                             -- slot4u → tenant havi jutalékszámla (saját, ÁFA-s SaaS-bevétel)
```

## Ügyfél-oldali fizetés & számlázás (opcionális, M6)

> Ez a tenant **saját** ügyfél-fizetése (Barion/Stripe a tenant javára) és a tenant ügyfél-számlázása — **független** a slot4u jutalék-beszedésétől (docs/10 §4). A `feature_online_payment` / `feature_invoicing` aktiválása a tenant jutalékrátáját 1,0%-ról 1,5%-ra emeli (docs/10 §2.1).

```
payments           id, tenant_id, booking_id, provider(barion|stripe), provider_ref,
                   amount_minor, currency, status(pending|paid|failed|refunded), paid_at, payload(json)
refunds            id, payment_id, amount_minor, reason, status(pending|completed|failed),
                   refunded_at               -- teljes/részleges/manuális/API refund (SLO-40)
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
                   reminder_24h|payment_success|payment_failed), channel(email|sms),
                   locale, subject, body, enabled
messages           id, tenant_id, sender_id, recipient_id, booking_id(nullable), body, read_at
notifications_log  id, tenant_id, type, recipient, channel, status, sent_at, error
audit_logs         id, tenant_id(nullable), user_id(nullable), action, auditable_type/id(nullable), old_values/new_values(json), ip_address, created_at(immutable, nincs updated_at)
```

## Statisztika

MVP: lekérdezés-alapú riportok indexelt oszlopokon (bookings.starts_at, status, staff_id, service_id) + napi aggregáló job egy `daily_stats` táblába (tenant_id, date, bookings_count, revenue_minor, new_customers). Külön BI nem kell.

## Phase 2 modulok (NEM MVP)

Bérlet- és csomagkezelés (packages, customer_packages, package_usage — SLO-58), membership rendszer (membership_plans, customer_memberships — SLO-59), egyedi ügyfélmezők és form builder (custom_fields, forms, form_submissions — SLO-60): teljes séma és üzleti szabályok a `07-phase2-modulok.md`-ben.
