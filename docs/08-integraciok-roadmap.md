# 08 — Integrációs roadmap

Prioritált terv a külső integrációkhoz. Minden integráció: feature flag mögött, tenant-onként kapcsolható, hívásai az `integration_logs`-ba naplózva (docs/06). A tenant-szintű API kulcsok/tokenek titkosítva tárolva.

## Prioritási elvek (PM)

1. **Bevételhez kötött először:** fizetés és számlázás nélkül nincs Max csomag — ezek nem is "integrációk", hanem MVP-funkciók (M6).
2. **Ügyfélmegtartó másodszor:** naptár-szinkron a no-show és duplafoglalás ellen — a célközönség (pszichológus, edző) leggyakoribb kérése.
3. **Growth harmadszor:** marketing/analytics — akkor ér valamit, amikor már van forgalom.
4. Egy kategórián belül EGY providerrel indulunk, absztrakciós réteg mögött — a második provider csak bizonyított igénynél.

## Roadmap táblázat

| Integráció | Kategória | Érték | Effort | Fázis | Feature flag / csomag |
|---|---|---|---|---|---|
| Stripe | Fizetés | ★★★ | M | **MVP (M6)** — elsődleges provider (recurring) | feature_online_payment / Max |
| Számlázz.hu | Számlázás | ★★★ | M | **MVP (M6)** | feature_invoicing / Max |
| Barion | Fizetés | ★★ | M | Phase 2 — hazai kártyás alternatíva, a PaymentProvider absztrakcióra | feature_online_payment / Max |
| Google Calendar | Naptár-szinkron | ★★★ | L | **Phase 2 — első igazi integráció.** Kétirányú: foglalás → dolgozó naptárába; dolgozó külső eseménye → availability-ből kivonva (busy-check). OAuth per staff. | feature_google_calendar / Közepes+ |
| Google Meet | Videó | ★★ | S (Calendar után) | Phase 2 — online szolgáltatásnál automatikus Meet link a foglaláshoz | feature_google_meet / Közepes+ |
| Billingo | Számlázás | ★★ | M | Phase 2/3 — InvoiceProvider absztrakcióra, igény szerint | feature_invoicing / Max |
| Outlook Calendar | Naptár-szinkron | ★★ | M | Phase 3 — a CalendarProvider absztrakcióra (MS Graph) | feature_outlook_calendar |
| Zoom | Videó | ★★ | M | Phase 3 — VideoProvider absztrakció, ha a Meet nem elég | feature_zoom |
| Google Analytics 4 | Analytics | ★★ | S | Phase 2 — tenant megadja a mérőkódot, foglalási funnel eventek (begin_checkout, purchase) | feature_analytics / Közepes+ |
| Meta Pixel | Analytics/Ads | ★★ | S | Phase 2 — GA4-gyel együtt, consent-höz kötve (GDPR!) | feature_analytics / Közepes+ |
| Mailchimp | Marketing | ★★ | M | Phase 3 — ügyfél-szinkron lista felé (opt-in!), foglalás-alapú szegmensek | feature_marketing_sync / Max |
| MailerLite | Marketing | ★ | S (Mailchimp után) | Phase 3 — MarketingProvider absztrakcióra | feature_marketing_sync / Max |
| ActiveCampaign | Marketing | ★ | M | Phase 3+ — csak konkrét ügyféligénynél | feature_marketing_sync / Max |
| Google Business Profile | Jelenlét | ★★ | L | Phase 3+ — "Reserve with Google" foglalási integráció; nagy érték, de partner-program + Public API előfeltétel | feature_api / Max |

Effort: S ≈ pár nap, M ≈ 1-2 hét, L ≈ 2+ hét (absztrakció + OAuth + edge case-ek).

## Fejlesztői irányelvek

- **Provider-absztrakció kategóriánként:** `PaymentProvider` (M6-ban készül), `InvoiceProvider`, `CalendarProvider`, `VideoProvider`, `MarketingProvider` — interfész + első implementáció együtt, a második provider már csak adapter.
- **OAuth tokenek:** staff-szintű (naptár) ill. tenant-szintű (marketing) tokenek titkosítva, refresh-flow job-bal, lejárat-riasztással.
- **Hibatűrés:** integráció-kiesés SOHA nem blokkolhat foglalást — minden külső hívás queue-ból, retry-jal; a foglalás a forrás-igazság (source of truth), a külső rendszer követi.
- **Naptár-szinkron konfliktus:** külső naptár-esemény és slot4u-foglalás ütközésénél a slot4u-foglalás él, a konfliktus admin-riasztást generál (nem automatikus törlést).
- **Consent:** Meta Pixel / GA4 csak cookie-consent után tölthet be; Mailchimp-szinkron csak explicit marketing opt-in-es ügyfeleket küld.

## PM döntési kapuk

- Új integráció csak akkor kerül fejlesztésbe, ha: (1) legalább 3 fizető tenant kérte VAGY csomag-differenciáló értéke van, (2) van provider-absztrakció vagy ebben az issue-ban készül el, (3) a GDPR-hatás tisztázott.
- Negyedévente roadmap-review: a táblázat prioritásai a tényleges tenant-igények alapján frissülnek.
- Minden integráció külön Linear issue-csomag (absztrakció, implementáció, admin UI, tesztek) — a Phase 2 indulásakor készül belőlük milestone.
