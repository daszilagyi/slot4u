# 08 — Integrációs roadmap

Prioritált terv a külső integrációkhoz. Minden integráció: feature flag mögött, tenant-onként kapcsolható, hívásai az `integration_logs`-ba naplózva (docs/06). A tenant-szintű API kulcsok/tokenek titkosítva tárolva.

## Prioritási elvek (PM)

1. **Bevételhez kötött először:** a tenant ügyfél-oldali fizetése/számlázása rátaemelő integráció (1,5% jutalék — docs/10 §2.1); ezek nem is "integrációk", hanem MVP-funkciók (M6). A slot4u saját bevétele a forgalom-alapú jutalék (docs/10), nem ezek a providerek.
2. **Ügyfélmegtartó másodszor:** naptár-szinkron a no-show és duplafoglalás ellen — a célközönség (pszichológus, edző) leggyakoribb kérése.
3. **Growth harmadszor:** marketing/analytics — akkor ér valamit, amikor már van forgalom.
4. Egy kategórián belül EGY providerrel indulunk, absztrakciós réteg mögött — a második provider csak bizonyított igénynél.

## Roadmap táblázat

| Integráció | Kategória | Érték | Effort | Fázis | Feature flag |
|---|---|---|---|---|---|
| Stripe | Fizetés | ★★★ | M | **MVP (M6)** — elsődleges provider, ügyfél-oldali foglalás-fizetés (nem recurring előfizetés) | feature_online_payment |
| Számlázz.hu | Számlázás | ★★★ | M | **MVP (M6)** | feature_invoicing |
| Barion | Fizetés | ★★ | M | Phase 2 — hazai kártyás alternatíva, a PaymentProvider absztrakcióra | feature_online_payment |
| Google Calendar | Naptár-szinkron | ★★★ | L | **Phase 2 — első igazi integráció.** Kétirányú: foglalás → dolgozó naptárába; dolgozó külső eseménye → availability-ből kivonva (busy-check). OAuth per staff. | feature_google_calendar |
| Google Meet | Videó | ★★ | S (Calendar után) | Phase 2 — online szolgáltatásnál automatikus Meet link a foglaláshoz | feature_google_meet |
| Billingo | Számlázás | ★★ | M | Phase 2/3 — InvoiceProvider absztrakcióra, igény szerint | feature_invoicing |
| Outlook Calendar | Naptár-szinkron | ★★ | M | Phase 3 — a CalendarProvider absztrakcióra (MS Graph) | feature_outlook_calendar |
| Zoom | Videó | ★★ | M | Phase 3 — VideoProvider absztrakció, ha a Meet nem elég | feature_zoom |
| Google Analytics 4 | Analytics | ★★ | S | **Kész.** Platform (SLO-172): csak `slot4u.hu`. Tenant (SLO-56): saját mérőkód + funnel eventek (`view_item`, `begin_checkout`, `purchase`) | feature_analytics |
| Meta Pixel | Analytics/Ads | ★★ | S | **Kész (SLO-56)** — a `marketing` consent-kategória mögött, `ViewContent` / `InitiateCheckout` / `Purchase`. A `Purchase` `eventID`-je a foglaláskód, hogy a szerver-oldali Conversions API event deduplikálható legyen | feature_analytics |
| Mailchimp | Marketing | ★★ | M | Phase 3 — ügyfél-szinkron lista felé (opt-in!), foglalás-alapú szegmensek | feature_marketing_sync |
| MailerLite | Marketing | ★ | S (Mailchimp után) | Phase 3 — MarketingProvider absztrakcióra | feature_marketing_sync |
| ActiveCampaign | Marketing | ★ | M | Phase 3+ — csak konkrét ügyféligénynél | feature_marketing_sync |
| Google Business Profile | Jelenlét | ★★ | L | Phase 3+ — "Reserve with Google" foglalási integráció; nagy érték, de partner-program + Public API előfeltétel | feature_api |

Effort: S ≈ pár nap, M ≈ 1-2 hét, L ≈ 2+ hét (absztrakció + OAuth + edge case-ek).

## Fejlesztői irányelvek

- **Provider-absztrakció kategóriánként:** `PaymentProvider` (M6-ban készül), `InvoiceProvider`, `CalendarProvider`, `VideoProvider`, `MarketingProvider` — interfész + első implementáció együtt, a második provider már csak adapter.
- **OAuth tokenek:** staff-szintű (naptár) ill. tenant-szintű (marketing) tokenek titkosítva, refresh-flow job-bal, lejárat-riasztással.
- **Hibatűrés:** integráció-kiesés SOHA nem blokkolhat foglalást — minden külső hívás queue-ból, retry-jal; a foglalás a forrás-igazság (source of truth), a külső rendszer követi.
- **Naptár-szinkron konfliktus:** külső naptár-esemény és slot4u-foglalás ütközésénél a slot4u-foglalás él, a konfliktus admin-riasztást generál (nem automatikus törlést).
- **Consent:** Meta Pixel / GA4 csak cookie-consent után tölthet be; Mailchimp-szinkron csak explicit marketing opt-in-es ügyfeleket küld. **A kapu készen áll (SLO-165):** `CookieConsent::allows('analytics'|'marketing')`, szerver oldalon eldöntve — a mérőkód így már meglévő döntés mögé landol, l. `docs/19` §11. A SLO-172 ezt a mintát már használja: a tag a **root Blade-ben** dől el, és ugyanaz az objektum tágítja a CSP-t, hogy a policy és a markup ne tudjon széttartani.
- **⚠️ Ki az adatkezelő:** a platform saját mérése kizárólag a központi domainen fut. Tenant-aldoménen a tenant az adatkezelő és a slot4u az adatfeldolgozó (`docs/19` §2) — ott csak a **tenant saját** mérőkódja szólalhat meg, a platformé soha.

## PM döntési kapuk

- Új integráció csak akkor kerül fejlesztésbe, ha: (1) legalább 3 aktív tenant kérte VAGY egyértelmű forgalom-növelő értéke van, (2) van provider-absztrakció vagy ebben az issue-ban készül el, (3) a GDPR-hatás tisztázott.
- Negyedévente roadmap-review: a táblázat prioritásai a tényleges tenant-igények alapján frissülnek.
- Minden integráció külön Linear issue-csomag (absztrakció, implementáció, admin UI, tesztek) — a Phase 2 indulásakor készül belőlük milestone.
