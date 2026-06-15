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

Superadmin extra: tenant CRUD + felfüggesztés/aktiválás, csomag- és feature-kezelés tenantonként, globális role/permission kezelés, globális statisztikák (aktív előfizetések, foglalásszám, userszám, MRR), impersonation (belépés tenant adminként, auditolva).

## Feature flagek (Pennant, tenant scope)

`feature_online_payment`, `feature_invoicing`, `feature_custom_domain`, `feature_waitlist`, `feature_quote_request`, `feature_approval_flow`, `feature_messages`, `feature_documents`, `feature_reports`, `feature_sms`, `feature_api`, `feature_nlp_booking` (AI foglalás, később), `feature_google_meet` (később).

Alapérték a csomagból (`plan_features`), superadmin tenantonként felülírhatja (`tenant_features`).

## Csomagok

| | **Alap** | **Közepes** | **Max** |
|---|---|---|---|
| Admin user | 1 | 10 | korlátlan |
| Dolgozó (staff) | 3 | 15 | korlátlan |
| Helyszín / helyiség | 1 / 3 | 5 / 25 | korlátlan |
| Cégprofil + foglalófelület | ✔ | ✔ | ✔ |
| Foglalási módok | mind a 6 | mind a 6 | mind a 6 |
| Email értesítések | ✔ | ✔ | ✔ |
| Foglalófelület testreszabás (branding) | — | ✔ | ✔ |
| Statisztika modul (költések, dolgozói aktivitás) | — | ✔ | ✔ |
| Üzenetküldés, várólista, jóváhagyás, ajánlatkérés | — | ✔ | ✔ |
| Online fizetés (Barion/Stripe) | — | — | ✔ |
| Számlázás (Számlázz.hu) | — | — | ✔ |
| Egyedi subdomain/domain | — | — | ✔ |
| SMS, API, dokumentumtár | — | — | ✔ (fázis 2) |

Limit-érvényesítés: `PlanLimitService::check(tenant, 'max_employees')` minden létrehozó actionben + UI-ban előre jelezve ("Elérted a csomagod limitjét — válts nagyobbra").

## Trial és státuszátmenetek

Regisztráció → 14 nap trial (Közepes csomag funkciói) → fizetés vagy lefokozás. `suspended` tenant: admin belép, csak figyelmeztető + fizetés oldal; publikus foglalófelület "átmenetileg nem elérhető" oldalt mutat. `archived`: 90 nap után anonimizálás/törlés (GDPR).
