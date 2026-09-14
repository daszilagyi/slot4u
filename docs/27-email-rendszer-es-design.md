# 27 — Rendszerlevelek: egységes design és szerkeszthetőség

> Forrás: SLO-243 (szülő), SLO-244 (1. lépés, **kész**), SLO-245 (2. lépés), SLO-246 (3. lépés).
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
- **`MailBrand`** (`app/Support/Mail/MailBrand.php`): a fejléc háttere és szövegszíne, a gomb háttere és szövegszíne, canvas, surface, ink, ink-muted, link, vonal, logó URL, lábléc-szöveg. Az alapértékek a docs/21 tokenjei (navy `#0D1B2A` fejléc, sárga `#F4B942` gomb navy szöveggel). Az `AppServiceProvider` **`bind`**-dal köti be, nem singletonnal: egy futó queue worker újraindítás nélkül is az aktuális márkát kell hogy használja. A 2. lépés ezt a bindingot cseréli az adatbázisban tárolt értékre.

### ⚠️ Logó

- **PNG, nem SVG:** a Gmail és az Outlook eldobja az SVG képet. A tile 96×96-os raszterképe: `resources/images/mail/brand-tile.png`, 40 px-en jelenik meg (2x).
- **Vite asset, nem `public/`:** az apex host nem szolgálja ki a `public/`-ot (SLO-233). A `resources/js/app.tsx` egy eager `import.meta.glob`-bal húzza be, csak azért, hogy manifest-bejegyzése legyen.
- **`?no-inline`:** 4 KB alatt a Vite data-URI-t csinálna a képből, és azt a Gmail nem mutatja.
- **Ha nincs build** (pl. tesztfutás assetek nélkül), a `logoUrl` null, és a fejlécben csak a szó áll, törött kép nélkül.

## 4. Következő lépések

- **SLO-245:** superadmin márka-beállítások (logó-feltöltés, színek, lábléc, kontraszt-ellenőrzés, élő előnézet). Platform-szintű tárolás, audit-log.
- **SLO-246:** superadmin levélszöveg-szerkesztő, a fenti feloldási sorrenddel. A tenant szerkesztő (SLO-114) alapértékként a platform szöveget mutatja.
