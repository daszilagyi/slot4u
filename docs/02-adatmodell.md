# 02 — Adatmodell (MVP)

Minden tenant-tulajdonú táblán: `tenant_id` (indexelt, FK), `created_at/updated_at`, soft delete ahol értelme van (`deleted_at`). Pénz: `*_minor` integer + `currency`. Idő: UTC datetime.

**Scope:** ez a dokumentum KIZÁRÓLAG az MVP sémát tartalmazza. A Phase 2 modulok (bérlet/csomag, membership, custom fields, form builder) sémája: `07-phase2-modulok.md` — azok a táblák az MVP migrációkba NEM kerülnek be.

## Tenant & előfizetés

> **Monetizáció:** a slot4u **forgalom-alapú jutalékkal** (havi jutalékszámla) monetizál, NEM fix havidíjas háromlépcsős csomaggal. Igazság-forrás: `10-arazasi-modell-jutalek.md`. A `plans`/`plan_limits`/`plan_features` táblák megmaradnak, de **egyetlen `base` plan**-t írnak le nagyvonalú limitekkel (a basic/mid/max **megszűnt**). A jutalék-séma a „Forgalom-alapú jutalék" szekcióban.

```
tenants            id, name, slug(subdomain), status(trial|active|suspended|archived),
                   timezone, locale, branding(json: logó, színek), settings(json)
tenant_domains     id, tenant_id, domain(UNIQUE), verification_token, verified_at,
                   is_primary, last_checked_at, last_error,              -- egyedi domain (feature_custom_domain, SLO-42)
                   provider_hostname_id, provisioning_status(pending|active|failed),
                   certificate_status, provisioning_error, provisioned_at -- edge provisioning (SLO-135)
                   -- tulajdonjog (verified_at) és kiszolgálhatóság (provisioning_status) KÜLÖN:
                   -- provider-hiba sosem vonja vissza a verifikációt; NULL status = nem volt kísérlet
                   -- domain: punycode/kisbetűs, port nélkül (ahogy a Host header érkezik);
                   -- a globális UNIQUE az, ami megakadályozza, hogy két tenant ugyanazt a hostot vigye
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

**Telefonszám-formátum (SLO-151).** Minden telefon-oszlop — `users.phone`, `locations.phone`, `bookings.guest_phone`, `quote_requests.guest_phone`, `tenants.settings.phone` — **E.164-ben** tárol (`+36301234567`). A konverzió a Form Requestben történik, a validáció **előtt** (`NormalizesPhone` trait), és a validáció maga (`App\Rules\Phone`) pontosan azt kérdezi, hogy a beírt szöveg E.164-re hozható-e — így a kettő nem tud elcsúszni. A parse-olást a Google libphonenumber végzi (`giggsey/libphonenumber-for-php-lite`); a **hívóhely nélkül beírt szám** (`06 30 …`) alapértelmezett országa a **tenant időzónájából** jön (`Europe/Budapest` → HU, l. `App\Support\PhoneNumber::regionForTimezone`), országhívóval bármely külföldi szám elfogadott. A mező mindenhol **opcionális**; üresen hagyva `null` kerül tárolásra, nem üres string.

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
invoices           id, tenant_id, booking_id, payment_id(unique), provider(sandbox|szamlazzhu),
                   provider_ref, number, amount_minor, currency,
                   status(pending|issued|storno|failed), pdf_path, issued_at,
                   storno_number, storno_pdf_path, stornoed_at, error
                   -- egy sor / kifizetett payment (SLO-133); `pending`-ként jön létre, a
                   -- queue-job tölti ki → provider-kiesésnél látható, újrapróbálható sor marad.
                   -- Teljes visszatérítés a SORT sztornózza (nem új sort nyit).
                   -- A PDF PRIVÁT diszken (`config/invoicing.disk`), csak auth-os letöltés.

tenants.invoicing  -- titkosított (encrypted:array) oszlop: számlázó API kulcs + eladó adatok
analytics_conversions id, tenant_id, booking_id, provider, event_name, event_id, status,
                   -- attempts, fbp, fbc, event_source_url, last_error, sent_at, timestamps
                   -- (SLO-173) Szerver-oldali hirdetési konverzió. UNIQUE(tenant_id, booking_id,
                   -- provider, event_name) — az idempotencia DB-szinten, nem a jó szándékon.
                   -- A sor a FOGLALÁSKOR jön létre (akkor olvasható a hozzájárulás és az
                   -- _fbp/_fbc süti), és akkor megy ki, amikor a foglalás eladássá válik.
                   -- Hozzájárulás nélkül NINCS sor — a sor hiánya a „nem" tartós rögzítése.
                   -- Az fbp/fbc a sikeres küldés után törlődik: utána cél nélküli személyes adat.

commission_invoices -- ÚJ oszlopok (SLO-143): number, provider_error, storno_ref, storno_pdf_path.
                   -- A külső BIZONYLAT állapota, ami NEM a számla státusza: az a tartozásról szól
                   -- (kiállítva/fizetve/lejárt, ezt olvassa a dunning), ez arról, készült-e papír.
                   -- Ezért nincs új CommissionInvoiceStatus érték: egy elbukott bizonylat nem
                   -- tehet úgy, mintha a tartozás lenne fura állapotban. `provider_error` nem null
                   -- = az utolsó próbálkozást elutasították, újrapróbálható.

tenants.analytics  -- titkosított (encrypted:array) oszlop (SLO-56): a tenant SAJÁT mérőkódjai
                   -- (ga4_measurement_id, meta_pixel_id) + a Conversions API hitelesítése
                   -- (meta_access_token, meta_test_event_code). Az azonosítók publikusak, a
                   -- TOKEN nem — ezért titkosított az egész oszlop, és ezért nem megy vissza
                   -- soha Inertia propba (csak a `hasMetaAccessToken` tény).
                   -- NEM fillable: külön képernyő írja (AnalyticsSettingsController).
                   -- (SLO-133). NEM a sima `settings` json-ban, és sosem megy Inertia propba.
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

### Számlázási adatok a foglaláson (SLO-168)

```
bookings   + wants_invoice(bool, default false), billing_name, billing_tax_number,
             billing_country_code, billing_post_code, billing_city, billing_address
             — MIND nullable: alapból nyugta készül, ami nem kér címet
             — ⚠️ a `bookings`-en és nem a `users`-en: TRANZAKCIÓS adat, az a cím,
               amire a bizonylat kiállt; nem változhat visszamenőleg attól, hogy
               az ügyfél elköltözött
             — az Áfa tv. 169. § e) a SZÁMLÁHOZ követeli meg a vevő nevét és
               címét; a nyugtához nem → csak attól gyűjtjük, akinek kell
             — a törlés (docs/19) mind a hat oszlopot nullázza
```

### Számlázó partner-leképezés (SLO-167)

```
invoicing_partners id, tenant_id, provider, email, partner_ref, timestamps
                   — unique(tenant_id, provider, email)
                   — ⚠️ azért létezik, mert a Billingo NEM tud partnert email
                     alapján keresni (a `?query=` névre illeszt) — enélkül minden
                     számla új partnert szórna a tenant számlázó-fiókjába
                   — névre keresni rossz alternatíva: a nevek ütköznek, és egy
                     elgépelt név javítása második partnerré tenné az ügyfelet
                   — email nélküli vevőnek nincs sora: minden alkalommal új
                     partner. Ritka és őszinte, nem érdemel rosszabb kulcsot
```

### Verziókövetett hozzájárulás (SLO-161, GDPR 7. cikk (1))

```
legal_documents    id, tenant_id(NULLABLE), type(terms|privacy), version, title,
                   body(nullable), url(nullable), effective_from, created_by_id, timestamps
                   — unique(tenant_id, type, version) · index(tenant_id, type, effective_from)
                   — tenant_id NULL = PLATFORM dokumentum (a slot4u ÁSZF-je, amit a
                     tenantok fogadnak el); kitöltve = a tenant sajátja, amit az
                     ügyfelei fogadnak el. Két külön szerződés, l. docs/19 §1.
                   — body VAGY url, sosem mindkettő: két forrás egy jogi szövegre
                     két szöveg
                   — hatályba lépett verziót SOHA nem írunk felül — új szöveg = új sor
                   — ⚠️ a unique index a platform-sorokra NEM véd (MySQL/SQLite a
                     NULL-t különbözőnek veszi); ott a Form Request az egyetlen őr
legal_consents     id, tenant_id, legal_document_id(RESTRICT), user_id(nullable),
                   email(nullable), context, accepted_at, ip_address, timestamps
                   — index(tenant_id, user_id, legal_document_id) · index(tenant_id, email)
                   — ⚠️ NEM `user_consents`: a belépési pontok fele vendégként fut
                     (bookings.guest_email), user sor nélkül. A user_id-ra kulcsolt
                     tábla ezeket rögzítetlenül hagyná, miközben teljesnek látszik.
                     Az alany user_id VAGY email.
                   — RESTRICT: elfogadott verziót nem lehet törölni — az a bizonyíték
                   — az ip_address itt MEGMARAD (nem söpri a retention, l. docs/19
                     §7.1): az audit sornál telemetria, itt maga a bizonyíték része
                   — minden elfogadás ÚJ sor, nincs deduplikáció: a hozzájárulás
                     cselekmény, a másodikat az elsőre ejteni bizonyítékot dob el
```

## Statisztika

MVP: lekérdezés-alapú riportok indexelt oszlopokon (bookings.starts_at, status, staff_id, service_id). Külön BI nem kell.

**Állapot (SLO-137):** a statisztika modul (`/reports`, `BuildTenantReport`) **élő lekérdezésekből** dolgozik, `daily_stats` aggregátum nélkül. Ez tudatos: az aggregátum bevezetése előtt tudni kell, mely dimenziók kellenek valójában, és egy visszamenőleg feltöltendő aggregátum-tábla önmagában is új hibaforrás (elcsúszás a forrásadattól). Amíg nincs, a **riport időszaka legfeljebb 366 nap** (`ReportRange::MAX_DAYS`, FormRequest-ben kényszerítve), hogy egy kérés ne tudja végigolvasni a tenant teljes történetét. A `daily_stats` tábla akkor jön, amikor ez a korlát valós tenant-adaton szűknek bizonyul.

**Kihasználtság:** a nevező (nyitvatartási perc) nem a riport saját számítása, hanem a foglalási motor nyitvatartás-szabálya (`App\Services\Schedule\WorkingWindows` — `schedules` sávok + `schedule_exceptions`), hogy a riport ne mondhasson mást arról, mikor van nyitva egy erőforrás, mint a foglalható idősávok.

## Phase 2 modulok (NEM MVP)

Bérlet- és csomagkezelés (packages, customer_packages, package_usage — SLO-58), membership rendszer (membership_plans, customer_memberships — SLO-59), egyedi ügyfélmezők és form builder (custom_fields, forms, form_submissions — SLO-60): teljes séma és üzleti szabályok a `07-phase2-modulok.md`-ben.
