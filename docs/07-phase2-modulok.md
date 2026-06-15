# 07 — Phase 2 modulok (bérlet, membership, custom fields, form builder)

Launch utáni modulok specifikációja. **Ezek a táblák NEM részei az MVP migrációknak** — implementáció csak a P2 backlog issue-k indításakor. A séma-konvenciók (tenant_id, `*_minor` pénz, UTC idő) a `02-adatmodell.md` szerint érvényesek itt is.

| Modul | Linear | Prioritás |
|---|---|---|
| Bérlet- és csomagkezelés | SLO-58 | P2 magas |
| Membership (ügyfél-előfizetés) | SLO-59 | P2 közepes |
| Custom fields + Form builder | SLO-60 | P2 magas |

(A "több telephelyes dolgozó" — staff_locations — átkerült az MVP-be: SLO-51, séma a 02-ben.)

## 1. Bérlet- és csomagkezelés (SLO-58)

Többalkalmas csomagok: 5 alkalmas gyógytorna, 10 alkalmas masszázsbérlet, 8 alkalmas reformer pilates, 3 konzultációs csomag, 20 alkalmas személyi edzés.

```
packages           id, tenant_id, name, description, price_minor, currency,
                   validity_days, total_sessions, active
package_services   package_id, service_id, session_count   -- mely szolgáltatásra hány alkalom
customer_packages  id, tenant_id, customer_id, package_id, purchased_at,
                   expires_at, remaining_sessions, status(active|expired|used_up|refunded)
package_usage      id, customer_package_id, booking_id, used_sessions, created_at
```

**Üzleti szabályok:**
- Foglalás fizethető pénzzel VAGY bérlettel — a fizetési mód a booking flow-ban választható, ha van érvényes bérlet.
- Foglaláskor atomi alkalomszám-csökkentés (ugyanaz a lock-elv, mint az event kapacitásnál: `UPDATE ... WHERE remaining_sessions >= n`).
- Lemondáskor tenant-szabály szerint visszakerül az alkalom (beállítás: mindig / lemondási határidőn belül soha).
- Lejárt bérlet nem használható; lejárat-figyelő job + emlékeztető email ("3 alkalom maradt", "1 hét múlva lejár").
- Egy ügyfélnek több aktív bérlete lehet — fogyasztási sorrend: legkorábban lejáró először.
- Bérlet-vásárlás = no_time_slot jellegű flow online fizetéssel (Max csomagnál), számlával.

**Érintett felületek:** bérlet CRUD (admin), bérlet-vásárlás (publikus), "bérleteim" (members area), ügyfélkarton bővítés, riport (eladott bérletek, beváltási arány).

## 2. Membership — ügyfél-előfizetés (SLO-59)

A bérlettől eltér: nem fix alkalomszám, hanem **időszak alapú hozzáférés**. Példák: havi korlátlan jóga, VIP tagság, havi 8 pilates, corporate membership.

```
membership_plans          id, tenant_id, name, billing_period(monthly|yearly),
                          price_minor, currency, session_limit(nullable=korlátlan), active
membership_plan_services  membership_plan_id, service_id, discount_percent(nullable), free(bool)
customer_memberships      id, tenant_id, customer_id, membership_plan_id, starts_at, ends_at,
                          status(active|past_due|canceled|expired), auto_renew
```

**Üzleti szabályok:**
- Aktív tagság → ingyenes vagy kedvezményes foglalás a plan-hez rendelt szolgáltatásokra.
- Automatikus megújítás a PaymentProvider recurring flow-ján (ugyanaz az infrastruktúra, mint a tenant-előfizetésnél — SLO-39); sikertelen terhelés → past_due → kedvezmény felfüggesztve.
- `session_limit`-es plan (havi 8 alkalom): periódusonként resetelő számláló, a limit felett normál ár.
- Elszámolási sorrend foglaláskor: 1. membership (ingyenes/kedvezmény) → 2. bérlet → 3. pénz. **Implementáció előtt PM-döntés:** ez a sorrend felülbírálható-e ügyfél által.

**Nyitott kérdések (issue indítása előtt tisztázandó):** corporate membership (egy vevő, több user) scope-ban van-e; tagság szüneteltetés (freeze) kell-e v1-ben.

## 3. Egyedi ügyfélmezők — custom fields (SLO-60)

A teljes általánosság kulcsa: tenant-onként más ügyféladatok. Pszichiáter: TAJ, anya neve, gyógyszerek. Szépségszalon: hajtípus, allergia. Állatorvos: állat neve, fajta. Autószerviz: rendszám, alvázszám.

```
custom_fields        id, tenant_id, entity_type(customer|booking), name, key,
                     field_type(text|textarea|select|multiselect|date|number|checkbox|file),
                     options(json), required, sensitive(bool), sort_order, active
custom_field_values  id, custom_field_id, entity_id, value(encrypted, ha sensitive)
```

**Üzleti szabályok:**
- Tenant szabadon hoz létre mezőket; szolgáltatáshoz is kapcsolható (csak adott szolgáltatás foglalásánál kérdezi).
- Megjelenik az ügyfélprofilban (admin + members area), exportálható (CSV / ügyfél-adatexport).
- **GDPR-kritikus:** a `sensitive=true` mezők (TAJ, egészségügyi adat) mezőszinten titkosítva (Laravel encrypted cast), megtekintésük külön permission (`customer.view_sensitive`), az adatexport/törlés (SLO-48) kiterjed rájuk. File mezőnél: privát storage, vírusellenőrzés, méretlimit.

## 4. Dinamikus űrlaprendszer — form builder (SLO-60)

Szolgáltatáshoz rendelhető előzetes kérdőívek: egészségügyi kérdőív, GDPR/beleegyező nyilatkozat, esemény-regisztrációs adatok.

```
forms             id, tenant_id, name, description, active
form_fields       id, form_id, label, field_type(custom fields típuskészlete),
                  options(json), required, sort_order
service_forms     service_id, form_id, required_before_booking(bool)
form_submissions  id, tenant_id, form_id, booking_id(nullable), customer_id,
                  answers(json, titkosítva ha érzékeny), submitted_at, pdf_path(nullable)
```

**Üzleti szabályok:**
- `required_before_booking` esetén a foglalási flow kitöltésig nem véglegesít.
- Kitöltésből PDF generálható (nyilatkozat-archiválás), a PDF a tenant privát storage-ába kerül.
- Űrlap-verziózás: kitöltött submission a kitöltéskori mezőkészletet őrzi (snapshot az answers-ben) — a form későbbi szerkesztése nem írja át a múltat.
- Beleegyező nyilatkozatnál: időbélyeg + IP naplózva (bizonyíthatóság).

## Közös fejlesztői megjegyzések

- Mindegyik modul feature flag mögé kerül: `feature_packages`, `feature_memberships`, `feature_custom_fields`, `feature_forms` — csomag-hozzárendelés PM-döntés (javaslat: Közepes+, a membership Max).
- A bérlet/membership elszámolás közös `PriceResolver` szolgáltatásba kerüljön (bemenet: service + customer → kimenet: ár + fizetési forrás), hogy a booking flow ne ágazzon szét.
- Riport-hatás: a statisztika modul (SLO-45) bevétel-számítása bővítendő bérlet/membership bevétel-elhatárolással (eladáskor vs felhasználáskor — könyvelési döntés, tisztázandó).
