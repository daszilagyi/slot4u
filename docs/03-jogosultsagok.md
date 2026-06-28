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

Superadmin extra: tenant CRUD + felfüggesztés/aktiválás, csomag- és feature-kezelés tenantonként, globális role/permission kezelés, globális statisztikák (aktív tenantok, foglalásszám, userszám, havi jutalékbevétel mint MRR-proxy — docs/10 §10), impersonation (belépés tenant adminként, auditolva).

**Megvalósítás (SLO-77):** az `admin.{central}` panel `auth` + `ensure.superadmin` mögött. Tenant-kezelés: lista (keresés/szűrés/lapozás, `withCount('users')`, N+1-mentes), részletek/szerkesztés (`UpdateTenantRequest`: név/slug/timezone/locale; slug egyedi + nem foglalt), státusz-átmenetek Action-ökön (`ChangeTenantStatus` felfüggesztés/aktiválás/archiválás[soft delete]; `ExtendTrial`; `SetTenantFeature` a `tenant_features` override-ra). Csomag-hozzárendelés tárgytalan (egyetlen `base` plan). Az audit log (SLO-78) és az impersonation (SLO-79) ezekre az Action-ökre épül.

## Feature flagek (Pennant, tenant scope)

`feature_online_payment`, `feature_invoicing`, `feature_custom_domain`, `feature_waitlist`, `feature_quote_request`, `feature_approval_flow`, `feature_messages`, `feature_documents`, `feature_reports`, `feature_sms`, `feature_api`, `feature_nlp_booking` (AI foglalás, később), `feature_google_meet` (később).

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
