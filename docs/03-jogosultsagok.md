# 03 — Jogosultságok, feature flagek, csomagok

Három FÜGGETLEN réteg, nem szabad összemosni:

1. **Permission/Role** — mit tehet a user a tenanton belül (spatie, teams=tenant_id)
2. **Feature flag** — mi van bekapcsolva a tenantnak (Pennant, tenant scope)
3. **Plan limit** — mennyiségi korlátok a csomag szerint

Egy művelet akkor engedélyezett, ha: tenant aktív ∧ feature engedélyezett ∧ limit nem sérül ∧ user permission megvan.

## Szerepkör-hierarchia

```
Super Admin (tenant_id = NULL, admin.slot4u.hu)
└── Tenant
    ├── Tenant Admin   – tenanton belül teljes jog (kivéve superadmin funkciók)
    ├── Manager        – operatív: foglalások, ügyfelek, riportok; NINCS: előfizetés, számlázás, jogosultság
    ├── Employee       – saját naptár, saját foglalások, saját ügyfelek
    └── Customer       – saját profil, foglalások, számlák, üzenetek, dokumentumok
```

## Permission mátrix (alap seed; tenant admin testreszabhatja a Manager/Employee role-okat)

| Permission | Tenant Admin | Manager | Employee | Customer |
|---|---|---|---|---|
| booking.view (összes) | ✔ | ✔ | saját | saját |
| booking.create | ✔ | ✔ | saját naptárba | ✔ (publikus flow) |
| booking.edit / cancel | ✔ | ✔ | saját | saját (szabály szerint) |
| booking.approve | ✔ | ✔ | — | — |
| customer.view / edit | ✔ | ✔ | saját ügyfelei | önmaga |
| service.manage | ✔ | — | — | — |
| staff.manage | ✔ | — | — | — |
| location.manage | ✔ | — | — | — |
| schedule.manage | ✔ | ✔ | saját | — |
| report.view | ✔ | ✔ | — | — |
| message.send | ✔ | ✔ | saját ügyfeleknek | tenant felé |
| template.manage | ✔ | — | — | — |
| billing.view / edit (tenant előfizetés) | ✔ | — | — | — |
| settings.edit | ✔ | — | — | — |
| role.manage (tenant-szintű) | ✔ | — | — | — |

Egyedi felülírás: user-szintű direct permission (spatie `model_has_permissions`) — a projektkövetelmény szerint "minden szolgáltatást szabadon lehessen engedélyezni userenként vagy csoportonként". Csoport = role; egyén = direct permission.

**Customer role scope (SLO-86):** a `customer` role **nem kap admin-panel permission kódot** (a seed grantja üres). A mátrix „önmaga/saját" oszlopa a customerre a **members area** (SLO-33/SLO-94) tulajdonjog-alapú (ownership) policy-jein keresztül valósul meg — a bejelentkezett ügyfél a saját `User` rekordja és a saját foglalásai *tulajdonosa* —, nem a coarse `customer.view/edit` / `booking.*` kódokon át (azok az admin panelt kapuzzák). Konkrétan: a `BookingPolicy::viewOwn` / `cancelOwn` abilityk a `booking.customer_id === user.id` tulajdonjogot ellenőrzik (permission nélkül); a members controller a **404**-re képezi a megtagadást (rejtett létezés, nem 403 — idegen foglalás megkülönböztethetetlen a nemlétezőtől, mint az admin ownership-guardnál). A lemondási határidőt maga a `CancelBooking(online: true)` action kényszeríti. A members area **id nélküli** végpontjai (profil, jelszó — SLO-96) nem kapnak Policy-t: kizárólag `$request->user()`-t érintik, így a tulajdonjog strukturálisan garantált (nincs mit id szerint ellenőrizni), az `ensure.customer` middleware pedig már kapuzza a csoportot. A publikus foglalás (`booking.create`) hitelesítetlen (vendég flow), így permissiont nem igényel. A **tenant admin panel** (a `{tenant}.{central}` hitelesített route-csoportja) `ensure.staff` middleware mögött van: csak staff role (tenant-admin/manager/employee) léphet be, mert több admin route (`/dashboard`, `/showcase`, `/profile`) nincs `can:`-gated — egy érvényes tenant-session-nel bíró customer ezeket különben elérné. Impersonáló superadmin (nincs tenant role-ja) átengedve. A members area saját, `ensure.staff` nélküli route-csoport lesz.

**Role permission-szerkesztő (SLO-141):** a tenant admin a `/settings/roles` oldalon (`role.manage`) maga szabhatja testre a **manager** és **employee** role permission-listáját; a változás **azonnal érvényesül** (a `SyncRolePermissions` action a mentés után üríti a spatie permission-cache-t), és role-onként visszaállítható a docs/03 mátrix szerinti alapértelmezettre. A katalógus domain szerint csoportosított (`PermissionGroup`), és **feature szerint jelölt**: egy kikapcsolt feature permissionje (`report.view` ↔ `feature_reports`, `booking.approve` ↔ `feature_approval_flow`, `message.send` ↔ `feature_messages`) **lockolt sorként látszik az okkal**, nem tűnik el — a néma hiány hibának olvasódik, a lockolt sor válasznak. Minden módosítás auditált (`role.permissions_updated` / `role.permissions_reset`, régi és új listával).

**A védőkorlátok (a `RolePolicy`-ben és a FormRequestben, nem az UI-ban):** a **tenant-admin** role nem szerkeszthető (a teljes jog a definíciója); a **customer** role sem (szándékosan nem kap admin-panel kódot — l. SLO-86 fent); **a saját role-odat nem szerkesztheted** (ez a „saját jogot nem lehet elvenni" kikényszeríthető alakja — más `role.manage`-dzsel bíró kolléga szerkesztheti, tehát a tenant nem tudja kizárni magát); és **admin-fenntartott kódok** (`billing.view`, `billing.edit`, `role.manage`) **soha** nem kerülhetnek másik role-ra. A `role.manage` azért fenntartott, mert aki role-t szerkeszthet, az egy kéréssel magának adhatna mindent — enélkül a billing-korlát formalitás lenne. Ismeretlen role-név → **404**, lockolt role → **403**, tiltott permission-kód → **422**. ⚠️ **Egy lockolt (feature-hez kötött) meglévő grantot a mentés MEGŐRIZ:** az űrlap nem küldi vissza, tehát enélkül egy független kapcsoló átbillentése némán visszavonná. A feature kikapcsolása így nem veszi el a grantot — a route-ot úgyis a feature-gate zárja —, csak szerkeszthetetlenné teszi.

Superadmin extra: tenant CRUD + felfüggesztés/aktiválás, csomag- és feature-kezelés tenantonként, globális role/permission kezelés, globális statisztikák (aktív tenantok, foglalásszám, userszám, havi jutalékbevétel mint MRR-proxy — docs/10 §10), impersonation (belépés tenant adminként, auditolva).

**Megvalósítás (SLO-77):** az `admin.{central}` panel `auth` + `ensure.superadmin` mögött. Tenant-kezelés: lista (keresés/szűrés/lapozás, `withCount('users')`, N+1-mentes), részletek/szerkesztés (`UpdateTenantRequest`: név/slug/timezone/locale; slug egyedi + nem foglalt), státusz-átmenetek Action-ökön (`ChangeTenantStatus` felfüggesztés/aktiválás/archiválás[soft delete]; `ExtendTrial`; `SetTenantFeature` a `tenant_features` override-ra). Csomag-hozzárendelés tárgytalan (egyetlen `base` plan). Az audit log (SLO-78) és az impersonation (SLO-79) ezekre az Action-ökre épül.

**Audit log (SLO-78):** minden superadmin tenant-művelet (`tenant.suspended` / `tenant.activated` / `tenant.archived` / `tenant.trial_extended` / `tenant.feature_toggled` / `tenant.updated`) immutábilis `audit_logs` bejegyzést hoz létre az `AuditLogger` service-en keresztül (`record(action, auditable, oldValues, newValues, tenantId)`): rögzíti a végrehajtót (`user_id`, jellemzően superadmin), az auditált entitást (`auditable_type/id`), a régi/új értékeket (JSON) és a kérés IP-jét. A naplózás az Action-ökbe van kötve, így entry-point-független (a Phase 2 API-ból is működik). Az `audit_logs` **nem** `BelongsToTenant` (platform-szintű, csak superadmin panelben olvasható; a `tenant_id` az auditált entitásra mutat, nem ownership). Read-only superadmin nézet: `/audit-logs` (művelet/tenant szűrés, N+1-mentes eager-load). **Megjegyzés:** a séma a `docs/02`-t követi (`user_id` + nullable `tenant_id`), nem az SLO-77 issue-ban felvetett polimorf `actor_type/actor_id`-t, mivel az MVP-ben minden actor user és a docs az igazság forrása.

**Impersonation (SLO-79):** a superadmin a tenant részletező oldaláról „Belépés adminként" gombbal beléphet egy tenant kontextusába. Modell: **a bejelentkezett felhasználó végig a superadmin marad** — az impersonation csak a megosztott (subdomain-szintű, `.{central}` cookie) session-ben jelöli, *melyik* tenantba léphet be az `Impersonation` service-en keresztül (`session('impersonation.tenant_id')`). Ezért minden impersonation alatti audit-bejegyzés automatikusan az eredeti superadminra íródik (`Auth::id()` nem változik), az `AuditLogger` külön ág nélkül. Az `EnsureUserBelongsToTenant` guard: superadminnál a szokásos admin-panel redirect helyett átengedi a kérést, **ha** az aktív impersonation épp ezt a tenantot célozza (más tenant → továbbra is redirect). A start Action a superadmin domainen (`POST admin.{central}/tenants/{id}/impersonate`, `impersonation.started` audit), a stop a tenant domainen (`DELETE {tenant}.{central}/impersonation`, `impersonation.stopped` audit) fut — utóbbi szándékosan az `ensure.tenant.active`/`ensure.user.tenant` guardokon **kívül**, hogy a kilépés felfüggesztett tenantból is működjön, és hogy a „kilépés" gomb azonos originről (a tenant aldoménről) POST-oljon. A kétirányú domain-váltás `Inertia::location`-nel (teljes oldalbetöltés) történik. UI: sárga „impersonation aktív" sáv + kilépés gomb (`AppLayout`), amely csak az impersonált tenant kontextusában jelenik meg (shared `impersonation` prop). Archivált tenant nem impersonálható (route-binding 404). Ismert korlát: a foglalási motor még nincs kész (M3), így tenant-oldali auditált művelet MVP-ben még nincs — az AC „minden művelet a superadminra íródik" garanciáját a változatlan `Auth` identitás biztosítja.

**Dolgozók + meghívás (SLO-17):** a `staff` rekord elsődlegesen **naptár-erőforrás** (név, titulus, bio, fotó, szín, `active`), `user_id` opcionális — dolgozó lehet login nélkül. Ha az admin (staff.manage) megad egy email-címet, az `InviteStaff` action a tenant team-jében **`employee` role-lal** hoz létre egy `User`-t, összeköti a staff rekorddal (`staff.user_id`), és egy **jelszó-beállító linket** küld emailben (`StaffInvitationNotification`). A link a jelszó-reset tokenre épül és a **tenant aldoménre** mutat (`{tenant}.{central}/reset-password/{token}`), így a meghívott a saját tenant-terébe érkezik; a meghívó email igazolja a címet, ezért a user már verifikáltként jön létre. A dolgozók száma a `max_employees` plan-limit alá esik (a `staff` rekordok száma számít, `CreateStaff`-ben érvényesítve). **Employee self-service:** a `StaffPolicy::update` a `staff.manage` mellett engedi a **saját** profil szerkesztését is (`staff.user_id === Auth::id()`); a `/profile` oldal (nem staff.manage-gated) ezt teszi elérhetővé — a nem összekötött usernek üres állapotot mutat. A törlésvédelem (`hasFutureBookings`) az M3 foglalási motorig no-op (mint a `Room`-nál). A „saját naptár" nézet M3-ra marad.

**Munkarend + kivételek (SLO-19):** a `schedules` (heti sávok) és `schedule_exceptions` (szabadság/ünnep/extra nyitás) táblák **polimorfak** (`schedulable_type`/`_id`, morph-alias `staff`|`room` — `AppServiceProvider` `Relation::morphMap`), így dolgozó és helyiség is kap munkarendet. Az idő-mezők a tenant időzónája szerinti **fali óra** értékek (nem instant): egy visszatérő heti mintát írnak le, a konkrét UTC-slotokra bontás és a DST-kezelés az M3 availability engine dolga (docs/01 §7). **Egy sáv egy napon belüli:** a záró idő szigorúan a kezdő után van — az **éjfélen átnyúló sáv explicit tiltott** (`ScheduleRequest`, docs/04 edge case). Ütközésvédelem: azonos erőforrás + azonos nap + átfedő érvényességi ablak esetén az időben átfedő sávok elutasítva. A „másolás napok közt" (`CopyScheduleDay`) a célnapok sávjait előbb törli, így idempotens és nem önátfedő. A munkarend-módosítás jövőbeli foglalásokkal való ütközését a `FutureScheduleConflicts` **figyelmeztető listaként** adja vissza (nem töröl automatikusan) — a `bookings` tábláig (M3) no-op, mint a `hasFutureBookings`. **Jogosultság:** `schedule.manage` (Tenant Admin + Manager) kezeli a teljes tenant munkarendjét a `/schedule` oldalon. Az Employee mátrixbeli „saját" scope-ja **ownership-alapú self-service**, amely a „saját naptár" nézettel együtt **M3-ra marad** (mint a staff `/profile`-nál) — az MVP-ben a `SchedulePolicy` egyelőre csak a permission-kaput ellenőrzi (nincs ownership-horog), az employee-önkiszolgáló belépési pontot az M3 vezeti be.

**Ügyfél-nyilvántartás (SLO-84):** az ügyfél = **tenant-scoped `User` a `customer` role-lal** (nincs külön `customers` tábla, docs/02); a `users.phone` oszlop az elérhetőséghez. A `/customers` admin oldal (`customer.view` lista + karton, `customer.edit` létrehozás/szerkesztés) az `App\Models\Customer` altípuson át kezeli őket (User a `users` táblán, `customer` role + tenant szűréssel; route-binding tenant+role-scope-olt → idegen/nem-customer id `404`). **Employee saját scope** (`App\Support\CustomerVisibility`): Tenant Admin/Manager minden ügyfelet lát, az Employee csak a **saját** ügyfeleit — akiknek van foglalása egy hozzá kötött (`staff.user_id === user`) staff-nál. Az **ügyfélkarton** aggregátumai: összes foglalás száma, teljesített foglalások, `total_spend_minor` (a `completed` foglalások `price_minor` összege). A **vendég-foglalás** (`FindOrCreateCustomer`): email alapján a tenant meglévő ügyfelét újrahasználja, egyébként újat hoz létre; idegen fiókhoz tartozó emailt tiszta validációs hibával utasít el (az MVP auth-modell **globálisan egyedi emailt** feltételez — egy user egy tenanthoz; a per-tenant email + tenant-aware login post-MVP follow-up). Az admin által létrehozott ügyfél belépni csak jelszó beállítása után tud (M4 members area); a booking-endpoint vendég-bekötése az admin foglaláskezelővel (SLO-85) / publikus flow-val (M4) jön.

## Feature flagek (Pennant, tenant scope)

`feature_online_payment`, `feature_invoicing`, `feature_custom_domain`, `feature_branding`, `feature_waitlist`, `feature_quote_request`, `feature_approval_flow`, `feature_messages`, `feature_documents`, `feature_reports`, `feature_sms`, `feature_api`, `feature_nlp_booking` (AI foglalás, később), `feature_google_meet` (később).

**Cégprofil + branding (SLO-21):** a `/settings` oldal (`settings.edit`, Tenant Admin) kezeli a tenant **cégprofilját** (név, leírás, elérhetőségek, nyitvatartás, social linkek) és a **foglalási szabályokat** (lemondási határidő órában, idősáv-rácsköz 15/30 perc). Ezek a `tenants.settings` json-ban tárolódnak, típusos `App\Settings\TenantSettings` value objecten át (default-okkal); a foglalási szabályokat az M3 motor fogyasztja. A **branding** (elsődleges szín, logó, borítókép — `tenants.branding` json, `App\Settings\TenantBranding`; a képek a `public` diszken tenant-prefix alatt) **`feature_branding`-gated**: alapból **kikapcsolt** (`enabledByDefaultOnBase() = false`), a szekció ilyenkor lockolt + CTA, és a `SettingsRequest` a közvetlen POST-ot is elutasítja; superadmin `tenant_features`-szel kapcsolja be tenantonként. **Eltérés az issue-tól:** az eredeti SLO-21 „Közepes+ csomag" / „Alap csomagnál lockolt" megfogalmazása a **megszűnt háromlépcsős csomagra** utalt — a docs (docs/03 §Csomag, docs/10) szerint minden funkció **feature-flag-gated, nem csomaghoz kötött**, ezért a branding gate feature-flag (`feature_branding`), nem plan-tier. A logó + elsődleges szín az admin chrome-ban (sidebar brand-blokk) és a publikus oldalon (SLO-29) a **közös `tenant` Inertia shared propból** (`HandleInertiaRequests::tenantIdentity()`) jelenik meg — ez a prop **`feature_branding`-gated** (SLO-90): kikapcsolt feature mellett `logo_url = null` és `primary_color = TenantBranding::DEFAULT_PRIMARY_COLOR`, így a gate a megjelenítésre is érvényes, nem csak a szerkesztőre. Ugyanígy `feature_branding`-gated a **borító** (`HomeController`) és az **OG-megosztókép** (`OgImageGenerator`, SLO-107; kikapcsolva default szín + logó nélkül, a gate a cache-kulcsba is beszámít) — a branding minden megjelenítési felülete egységesen a feature mögött van.

Alapérték a csomagból (`plan_features`), superadmin tenantonként felülírhatja (`tenant_features`).

## Csomag (egyetlen base plan) + forgalom-alapú jutalék

> A háromlépcsős fix előfizetés (Alap/Közepes/Max) **megszűnt**. A monetizáció **forgalom-alapú jutalék** havi jutalékszámlán — igazság-forrás: `10-arazasi-modell-jutalek.md`. Itt csak a jogosultság/limit-réteg szempontjából lényeges rész.

A foglalási motor **mindenkinek ingyenes**, egyetlen `base` plan nagyvonalú limitekkel. Minden funkció (branding, statisztika, üzenetküldés, várólista, jóváhagyás, ajánlatkérés, online fizetés, számlázás, egyedi domain) **feature flagen** (Pennant, `tenant_features`) keresztül kapcsolható — nem csomaghoz kötött. A rátaemelő integrációk (`feature_online_payment`, `feature_invoicing`) bekapcsolása nem fizetős add-on, csak a jutalékrátát emeli 1,0% → 1,5% (docs/10 §2.1).

**Base plan limitek (default javaslat, superadmin felülírható — docs/10 §15.2):**

| | **base** |
|---|---|
| Admin user | nagyvonalú default |
| Dolgozó (staff) | 3 |
| Helyszín / helyiség | 1 / 3 |
| Foglalási módok | mind a 6 |

Limit-érvényesítés: `PlanLimitService::check(tenant, 'max_employees')` minden létrehozó actionben + UI-ban előre jelezve ("Elérted a csomagod limitjét"). A pontos default limiteket a J3 (SLO-66) base-plan átállás véglegesíti.

## Trial és státuszátmenetek

Regisztráció → 14 nap trial (a base plan teljes funkciókészletével) → trial végén **nincs csomag-lefokozás** (nincs mire), a tenant a base planen `active`-ba lép. A monetizáció a forgalom-alapú jutalékon keresztül történik, nem havidíjas előfizetésen.

`suspended` tenant a **jutalékszámla nemfizetése** miatt (docs/10 §6.6: határidő → emlékeztetők → türelmi idő → felfüggesztés): admin belép, csak figyelmeztető + jutalékszámla-fizetés oldal; publikus foglalófelület "átmenetileg nem elérhető" oldalt mutat. `archived`: 90 nap után anonimizálás/törlés (GDPR).

**Megvalósítás (SLO-120):** a `/billing` route-ok (megtekintés + CSV-export + számla-PDF) szándékosan az `ensure.tenant.active` middleware-en **kívül** élnek, csak `identify.tenant` + `auth` + `ensure.staff` + `can:billing.view` mögött — így a felfüggesztett tenant adminja eléri a jutalékszámláit (látja, mit kell rendeznie), miközben a publikus foglalófelület és a többi admin-oldal 503-at ad. A billing oldal `suspended` állapotban figyelmeztető bannert mutat, a 503 `Tenant/Suspended` oldal pedig a tenant bejelentkezett tagjának linket kínál a `/billing`-re. Archivált tenant az `identify.tenant`-nél 404-el (soft-deleted, nem oldódik fel), tehát a billing sem érhető el neki. A tenant-önkiszolgáló **fizetés** nincs MVP-scope-ban — a „fizetettnek jelölés" a superadmin (SLO-122); itt a **megtekintés** a tét.
