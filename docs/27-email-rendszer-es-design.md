# 27 — Rendszerlevelek: egységes design és szerkeszthetőség

> Forrás: SLO-243 (szülő), SLO-244 (1. lépés, **kész**), SLO-245 (2. lépés, **kész**), SLO-246 (3. lépés).
> Daniel döntései, 2026-09-14: minden tenant rendszerlevele és emlékeztetője egységes design-t mutasson, és ez a superadmin felületen szerkeszthető legyen.

## 1. Cél és döntések

| Kérdés | Döntés |
|---|---|
| Mit szerkeszt a superadmin? | A platformleveleket (megerősítés, jelszó, dolgozói meghívó, jutalékszámla, archiválás) **és** az ügyfélnek menő levelek/emlékeztető **alapszövegét**. A tenant saját felülírása (SLO-114) megmarad. |
| Hogyan szerkeszthető a design? | Közös, márkázott levélsablon a kódban. A superadmin **márka-beállításokat** állít (logó, színek, lábléc) élő előnézettel. Nyers HTML-szerkesztés **nincs**: azzal a levél szerkezete elrontható lenne (Outlook, mobil), és biztonsági kockázat. |
| Mi áll az ügyféllevél fejlécében? | slot4u keret és színek, a fejlécben a **tenant neve**, alul „Ezt a levelet a(z) X küldte a slot4u foglalási rendszerén keresztül.” |
| Milyen a szerkeszthető szöveg? | Egyszerű formázás (bekezdés, félkövér, link, felsorolás), biztonságosan szűrve, `:változó` helyettesítéssel. |

Szövegfeloldási sorrend (a 3. lépéstől): **tenant felülírás → platform szöveg → lang default.**

## 2. A levelek leltára

Minden levél `MailMessage`-alapú notification, tehát **mind ugyanazon a kereten megy át**. Nincs Mailable, és nincs saját Blade-nézet egyetlen levélnél sem.

| Levél | Címzett | Küldő a keretben |
|---|---|---|
| `VerifyEmail` (framework) | regisztráló user | slot4u |
| `ResetPassword` (framework) | bármely user | slot4u |
| `StaffInvitationNotification` | meghívott dolgozó | slot4u |
| `CommissionInvoiceNotification` (3 változat) | tenant adminok | slot4u |
| `TenantArchivedNotification` | tenant adminok | slot4u |
| `CustomerMessageNotification` | staff | slot4u |
| 8 × `TenantMailNotification` (foglalás visszaigazolva / módosítva / lemondva / elutasítva, várólista-ajánlat, árajánlat, 24 órás emlékeztető, új üzenet) | ügyfél / vendég | **tenant** |

## 3. A keret (SLO-244)

- **Téma:** `config/mail.php` → `markdown.theme = slot4u` → `resources/views/mail/slot4u.blade.php`. Blade-nézet, nem statikus CSS, mert a színeket a `MailBrand`-ből olvassa. A Markdown-renderelő a CSS-t inline stílusként írja a levélbe.
- **Felülírt komponensek** (`resources/views/vendor/`): `mail/html/layout` (a navy fejléc a kártya első sora), `mail/html/header` (tile + szó, vagy a tenant neve), `mail/html/message` és `mail/text/message` (lábléc), `notifications/email` (köszönés, aláírás, a gomb alatti tartalék-link). A többi framework-komponens változatlan, ezért nincs publikálva.
- **Szövegek:** a framework angol sorai („Hello!”, „Regards,”, „If you're having trouble…”, „All rights reserved”) helyett `app.mail.layout.*`. A megerősítő és a jelszó-visszaállító levél `app.mail.verify_email.*` / `app.mail.reset_password.*` (`App\Notifications\Platform\AuthMailMessages`, `toMailUsing`). A linkek a frameworkéi maradnak.
- **Tenant név:** a `TenantMailNotification::addressAsTenant()` a `viewData['tenantName']`-be teszi. Ettől a fejléc, az aláírás és a lábléc a tenantot nevezi meg.
- **`MailBrand`** (`app/Support/Mail/MailBrand.php`): a fejléc háttere és szövegszíne, a gomb háttere és szövegszíne, canvas, surface, ink, ink-muted, link, vonal, logó URL, lábléc-szöveg. Az alapértékek a docs/21 tokenjei (navy `#0D1B2A` fejléc, sárga `#F4B942` gomb navy szöveggel). Az `AppServiceProvider` **`bind`**-dal köti be, nem singletonnal: egy futó queue worker újraindítás nélkül is az aktuális márkát kell hogy használja. Az SLO-245 óta a binding a `MailBrandStore::current()`-et adja (l. §4).

### ⚠️ Logó

- **PNG, nem SVG:** a Gmail és az Outlook eldobja az SVG képet. A tile 96×96-os raszterképe: `resources/images/mail/brand-tile.png`, 40 px-en jelenik meg (2x).
- **Vite asset, nem `public/`:** az apex host nem szolgálja ki a `public/`-ot (SLO-233). A `resources/js/app.tsx` egy eager `import.meta.glob`-bal húzza be, csak azért, hogy manifest-bejegyzése legyen.
- **`?no-inline`:** 4 KB alatt a Vite data-URI-t csinálna a képből, és azt a Gmail nem mutatja.
- **Ha nincs build** (pl. tesztfutás assetek nélkül), a `logoUrl` null, és a fejlécben csak a szó áll, törött kép nélkül.

## 4. Superadmin márka-beállítások (SLO-245)

**Hol:** `admin.{central}/emails/design` (a superadmin vezérlőpult „Email-design” linkje). Csak superadmin: a host (`ensure.superadmin` + `ensure.2fa`) és a `PlatformSettingPolicy::manage` is véd, tenant admin minden jogosultsággal is 403.

**Mit állít:**

| Mező | Megjegyzés |
|---|---|
| Fejléc színe | A fejlécszöveg színe **származtatott** (`MailBrand::readableTextOn`): fehér, navy vagy fekete, amelyik elsőként eléri a 4,5:1-et. Rossz választás így nem lehet. |
| Gomb színe | A gombfelirat színe ugyanígy származtatott (az alap sárgán navy marad). |
| Háttérszín | A lábléc fix `#5B6B7C` szövege ezen áll. **Mentési szabály: legalább 4,5:1**, különben validációs hiba. |
| Lábléc szövege | Opcionális, max. 300 karakter, sima szöveg (escapelve). |
| Logó | PNG/JPG, max. 512 KB, 40–1200 px. SVG-t és WebP-t nem fogad (a levelezők eldobják). A `public` diszkre kerül (`platform/mail/…`), a levélben `APP_URL/storage/...` URL-lel. Csere vagy törlés után a régi fájl a commit **után** törlődik. Logó nélkül a slot4u tile marad. A logó csak a slot4u saját levelein látszik, tenant-levélen a tenant neve áll a fejlécben. |

**Tárolás:** `platform_settings` tábla (`key` unique, `value` JSON, `updated_by`), `mail_brand` kulcs. **Nem `BelongsToTenant`**: minden tenant levele ezt olvassa, tenant-kontextusban is. A `MailBrandStore` a sort a megosztott cache-ben tartja (`rememberForever`), és íráskor a commit után felejti el. Így egy futó queue worker újraindítás nélkül a következő levéltől az új márkát használja.

**Audit:** `platform.mail_brand_updated` / `platform.mail_brand_reset`, régi és új értékkel, `tenant_id = null`.

**Visszaállítás:** a sor és a feltöltött logó törlődik, a levelek a kódbeli alapra esnek vissza.

**Élő előnézet:** a `POST /emails/design/preview` (throttle 120/perc) a **valódi levélkeretet** rendereli a piszkozat-márkával. A kérés idejére `app()->instance()` cseréli a bindingot, utána visszaáll. A válasz: `{html, footer_contrast, footer_contrast_ok}`. A React oldal 300 ms debounce-szal kéri le, és egy `sandbox=""` iframe `srcdoc`-jába teszi.
- **Két minta:** slot4u levél (megerősítés) és tenant levél (foglalás-visszaigazolás „Minta Szalon” névvel).
- **Rossz kontraszt:** az előnézet ilyenkor is kirajzolja a levelet, figyelmeztetéssel, a mentés gomb pedig tiltott.
- ⚠️ **Logó az előnézetben data-URI-ként:** az admin host CSP-je (`img-src 'self' data:`) nem engedi az apex `/storage` URL-t. Valódi levélben data-URI sosem megy ki.

**Hibatűrés:** ha a beállítás nem olvasható (pl. deploy a kódváltás és a migráció között), a binding `QueryException`-re a kódbeli alapmárkát adja, és a levél kimegy.

## 5. Következő lépés

- **SLO-246:** superadmin levélszöveg-szerkesztő, a fenti feloldási sorrenddel. A tenant szerkesztő (SLO-114) alapértékként a platform szöveget mutatja.
