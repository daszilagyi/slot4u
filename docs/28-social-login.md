# 28 — Social login: Google + Facebook

**Issue:** SLO-250 (epik) → SLO-251 (mag), SLO-252 (foglalási flow, profil, FB e-mail nélkül — §4–§5), SLO-253 (Meta data-deletion — §6.1).
**Csomag:** `laravel/socialite`.

## 1. A probléma, amit az architektúra megold

A Google és a Meta **csak pontosan regisztrált redirect URI-t** fogad el. Wildcard (`*.slot4u.hu`) nem regisztrálható, és egy tenant saját domainje (`foglalas.cegem.hu`) sem. Közben a belépésnek azon a hoston kell létrejönnie, ahol a látogató van: a saját domain **külön sessiont** kap (docs/01, „Egyedi tenant-domain”), oda a központi domainen létrehozott session el sem jutna.

Ezért a folyamat három hoston át megy, és két rövid életű, egyszer használható kulcs viszi:

```mermaid
sequenceDiagram
    participant B as Böngésző
    participant T as Induló host<br/>(acme.slot4u.hu / foglalas.cegem.hu / slot4u.hu)
    participant P as Google / Meta
    participant C as slot4u.hu<br/>(központi callback)

    B->>T: GET /auth/{provider}/redirect?intent=login&return=/book?...
    Note over T: flow-rekord a cache-be (10 perc):<br/>provider, intent, tenant_id,<br/>consume URL ezen a hoston, return path, nonce-hash<br/>+ nonce a TENANT-HOST sessionjébe
    T-->>B: 302 → provider (state = flow-kulcs,<br/>redirect_uri = slot4u.hu/auth/{provider}/callback)
    B->>P: bejelentkezés, hozzájárulás
    P-->>B: 302 → slot4u.hu/auth/{provider}/callback?code&state
    B->>C: GET callback
    Note over C: flow elköltése (egyszer), code-csere →<br/>provider-identitás VAGY hibakulcs,<br/>handoff token a cache-be (120 mp).<br/>SEMMIT nem hoz létre, nem kapcsol.
    C-->>B: 302 → {induló host}/auth/social/consume?token=…
    B->>T: GET consume
    Note over T: token elköltése (egyszer), nonce egyezik?<br/>tenant egyezik? CSAK EZUTÁN ResolveSocialLogin<br/>(link / ügyfél-létrehozás), 2FA? → kihívás,<br/>különben Auth::login
    T-->>B: 302 → return path / saját kezdőoldal
```

| Végpont | Hol | Route név |
|---|---|---|
| `GET /auth/{provider}/redirect` | központi domain + tenant hostok (saját domainen a host-átírással) | `social.redirect`, `tenant.social.redirect` |
| `GET /auth/{provider}/callback` | **csak** a központi domain | `social.callback` |
| `GET /auth/social/consume` | központi domain + tenant hostok | `social.consume`, `tenant.social.consume` |

Az admin panel hostján (`admin.slot4u.hu`) egyik sincs, és a login oldal nem is kínál gombot: a superadmin csak jelszóval + 2FA-val léphet be.

Kód: `App\Http\Controllers\Auth\Social{Redirect,Callback,Consume}Controller`, `App\Services\SocialAuth\*` (`SocialAuthBroker` = flow és token, `SocialAuthUrls` = callback URI, felkínált providerek, return path), `App\Actions\SocialAuth\ResolveSocialLogin`.

## 2. Biztonsági döntések

### 2.1 A flow az induló hoston kezdődik (eltérés a SLO-250 leírásától)

A brief szerint a központi domain adná az indító végpontot, a tenant és a return URL paraméterként érkezne, a return URL-t pedig host-allowlistával kellene ellenőrizni. Ehelyett **az induló host maga hozza létre a flow-t**, ezért:

- **Open redirect nem validációs kérdés, hanem kifejezhetetlen.** A cél-host a flow-rekordba írt consume URL (`url()`, tehát saját domainen a látogató hostja), a return csak **relatív útvonal** lehet (`SocialAuthUrls::safeReturnPath`: nincs séma, host, `//`, `\`, vezérlőkarakter, és nem `/auth/...`). Nincs allowlist, amit el lehetne rontani.
- **Login-CSRF védelem.** A consume csak akkor vált be tokent, ha a böngésző sessionjében ott a flow indításakor írt nonce. Enélkül egy támadó a saját Google-fiókjával végigjátszott flow consume-linkjét elküldhetné valakinek, aki így a *támadó* fiókjába lépne be (és ott foglalna, adatot adna meg).
- **A callback nem dönt és nem ír.** A callback nem tudja, kinek a böngészőjével beszél: a támadó a saját redirectjéből kimásolt provider-URL-t (benne a `state`-tel) elküldheti valakinek, aki a Google-nél belép. Ha a callback már itt létrehozná az ügyfelet vagy kapcsolná az identitást, az áldozat e-mailje a támadó választotta tenantnál kötne ki (és a globális e-mail-egyediség miatt máshol nem is regisztrálhatna). Ezért a callback csak a code-cserét végzi, a `ResolveSocialLogin` — minden írás — a consume-ban fut, a nonce- és tenant-ellenőrzés **után** (a SLO-251 biztonsági review lelete, teszt: „creates and links nothing for a callback that reaches a browser which did not start the flow”).
- **Tenant-kötés.** A `.slot4u.hu` session az aldomének között közös, ezért az *A* tenanton indított token a *B* tenant hostján is látná a nonce-t. A consume ezért a flow `tenant_id`-ját is összeveti az aktuális tenanttal.

A szerep-kontextust sem a kliens adja (`SocialIntent` = `login | booking | link`, nem szerepkör): lásd §3.

### 2.2 Egyszeri használat, rövid élet

| Kulcs | Élettartam | Hol él |
|---|---|---|
| flow-kulcs (OAuth `state`) | 10 perc (`FLOW_TTL_SECONDS`) | cache, sha256-kulccsal |
| handoff token | 120 mp (`HANDOFF_TTL_SECONDS`) | cache, sha256-kulccsal |
| nonce | a flow-ig, sessionönként max. 5 nyitott flow | az induló host sessionje |

⚠️ Az „egyszer” egy `Cache::add(...:spent)` marker, nem `Cache::pull`: a prod cache store az adatbázis, ahol a get+delete két utasítás (két párhuzamos kérés mindkettő megkaphatná az értéket), az `add` viszont egyetlen atomi insert (Redisen `SET NX`).

Rate limit: `throttle:social` — 20/perc IP-nként mindhárom végponton (IP és nem host szerint, mert a lépések különböző hostokon futnak).

### 2.3 Egy provider-belépés első faktor

Ha a fióknak be van kapcsolva a 2FA, a consume nem léptet be, hanem a Fortify saját átadását csinálja (`login.id` a sessionbe → `/two-factor-challenge`), pontosan úgy, ahogy a jelszavas belépés.

### 2.4 Tokent nem tárolunk

A Socialite által visszaadott access/refresh token a `SocialIdentity` DTO-ban eldobódik. A provider API-ját nem hívjuk a user nevében, csak azonosítunk.

## 3. Kit léptet be, kit hoz létre (`ResolveSocialLogin`)

Sorrendben — a sorrend maga a biztonsági modell:

1. **Már kapcsolt identitás** (`social_accounts` provider + provider_user_id) → az a user. A tárolt szolgáltatói profil frissül.
2. **Nincs e-mail** (Facebook telefonos fiók) → a feloldás megáll, és az e-mail-bekérő lépés jön (§5.3). Megerősítés előtt semmi nem jön létre és nem kapcsolódik.
3. **A provider nem vállalja az e-mailt** → `email_unverified`. Google: `email_verified`. Facebook: csak megerősített címet ad vissza, ezért a meglévő cím verifikáltnak számít (Daniel döntése, SLO-250).
4. **Verifikált e-mail egyezik egy fiókkal** → kapcsolás + belépés, szerepkörtől függetlenül (admin, staff, ügyfél).
   - ⚠️ **Pre-hijack:** ha a fiók e-mailje *sosem volt megerősítve*, a kapcsoláskor a jelszó `NULL` lesz, a `remember_token` és minden session törlődik. Aki a más e-mailjével előre regisztrált, nem marad bent.
   - ⚠️ A „verifikált” jelzés ezért csak bizonyított postafiókot jelenthet (SLO-254): a cím **a reset/meghívó link használatakor** lesz verifikált (`ResetUserPassword`), nem a meghívó kiküldésekor, és ha az admin **átírja** egy ügyfél e-mailjét, a jelzés törlődik (`UpdateCustomer`). Enélkül egy admin idegen címet írhatna egy általa ismert jelszavú fiókra, és a cím valódi tulajdonosának Google-belépése ebbe a fiókba kapcsolódna a régi jelszóval együtt.
   - Ha a fióknak már van **másik** identitása ugyanennél a providernél → `not_available` (a csendes csere a fiókot adná át).
5. **Nincs fiók**
   - központi domainen → `no_account`. Céget az űrlap regisztrál, nem egy Google-fiók.
   - tenant hoston → **ügyfél** jön létre (`CreateCustomer`, `passwordless`, `email_verified_at` = most). Semmilyen input nem kér szerepkört, tehát social úton **tenant-admin vagy staff fiók nem hozható létre** — staff csak azért létezik, mert valaki jogosult létrehozta.

**Mindig elutasítva, semleges üzenettel (`not_available`):** superadmin, anonimizált fiók, és tenant hoston **másik tenant usere**. Az e-mail globálisan egyedi (egy e-mail = egy user = egy tenant), így ugyanaz a Google-fiók két cégnél nem lehet ügyfél. A semleges üzenet azért kell, hogy a gomb ne árulja el, van-e fiókja valakinek egy másik cégnél. A foglalási flow-ban ilyenkor belépés nélküli vendég-előtöltés jön (§5.1).

Új ügyfélnél a jogi dokumentumok elfogadását **nem** a social flow kéri: az `EnsureLegalConsent` az első védett kérésnél a `/consent` oldalra viszi, mint minden el nem fogadott verziónál (docs/19 §10.5).

## 4. Jelszó nélküli fiókok

- `users.password` nullable. A `NULL` jelszavú fiókra a jelszavas login **nem hash-összehasonlítást** csinál (`Fortify::authenticateUsing`), hanem saját üzenetet ad (`auth.social_only`) — ⚠️ **de csak az adott tenant host saját userének.** Az e-mail-keresés platformszintű, ezért másik tenant userére és a központi domainen az általános `auth.failed` jön, különben bármelyik login form elárulná, hogy egy cím valahol Google/FB-bel regisztrált. Nem létező e-mailre és rossz jelszóra a válasz változatlanul `auth.failed`.
- Az `AuthenticateSession` a jelszó nélküli usert kihagyja (keretrendszer-viselkedés), a „kilépés máshonnan jelszóváltáskor” így nem értelmezett rájuk.
- Első jelszót **csak e-mailben kapott linkkel** állíthat be: a `/my/profile` „Jelszó beállítása” kártyája jelszó-beállító (reset) linket küld a fiók címére (`POST /my/password/link`), ugyanazt, amit az „Elfelejtetted a jelszavad?” ad. ⚠️ Helyben, a jelenlegi jelszó nélkül **nem** állítható be: a session nem bizonyítja a postafiókot, egy ellopott session így tartós hitelesítő adattá válna (és utána a tulaj providerét is le lehetne választani). A `PUT /my/password` mindig kéri a jelenlegi jelszót (SLO-252 biztonsági review).
- Az anonimizálás továbbra is **véletlen** jelszót ír, nem `NULL`-t (docs/19 §3.2).

## 5. Foglalás, fiók-kapcsolás, e-mail nélküli provider (SLO-252)

### 5.1 A foglalási varázsló

- Az „adataid” részen (időpontos, időpont nélküli, ajánlatkérés és esemény-jelentkezés) **csak vendégnek** jelennek meg a gombok (`social_providers` prop), `intent=booking`, a return az aktuális `page.url` — benne a szolgáltatás, a dátum és a kiválasztott `start`.
- A böngésző a providerhez teljes oldalbetöltéssel megy el, ezért ami **nem** az URL-ben van (telefon, megjegyzés, számlázási adatok, kiválasztott esemény, ajánlatkérő válaszok) a kattintás előtt `sessionStorage`-be kerül (`resources/js/lib/bookingDraft.ts`, 30 perc, útvonalhoz kötve), és visszatéréskor egyszer, mountkor visszatöltődik. A jogi pipát **soha** nem tölti vissza: az elfogadás azon az oldalon történik, ahol a szöveg látszik. A név és az e-mail a belépett fiókból jön, nem a piszkozatból.
- **Sikeres belépés** → vissza pontosan ugyanarra a lépésre, belépve (a mezők a fiókból előtöltve).
- **Elutasítás** → hibaüzenet ugyanazon a lépésen, nem a login oldalon (`SocialLoginFlow::failurePath`).
- **Az e-mail egy olyan fiókhoz tartozik, amit ez a host nem léptethet be** (gyakorlatilag egy másik cég ügyfele, `not_available`): **nincs belépés**, a név + a provider által verifikált e-mail flash-sel a foglalási űrlapba kerül (`social_prefill`), a vendég így foglal tovább. Pontosan azt kapja, mintha kézzel írta volna be (a `ResolvePublicContact` vendégként rögzíti), és a másik fiókról semmit nem tud meg — a saját nevét és címét látja. Login intentnél ilyenkor nincs előtöltés, csak a semleges hiba.

### 5.2 Kapcsolás és leválasztás a profilban

- **Kapcsolás:** `/auth/{provider}/redirect?intent=link` — csak belépett, a host tenantjához tartozó **ügyfélnek** (vendég/idegen tenant → `/login`, **staff → 404**). A flow rögzíti a user id-t, és a consume **csak ugyanannak a belépett usernek** kapcsol (`SocialLoginCompleter::link`); közben kicserélt session → `expired`, semmi nem kapcsolódik.
- ⚠️ **Egy kapcsolt provider túléli a sessiont, ami létrehozta** (jelszóváltás sessiont zár, linket nem). Ezért (SLO-252 biztonsági review):
  - **Staff nem kapcsolhat**: neki nincs felülete, ahol látná vagy leválaszthatná — egy ellopott admin-session láthatatlan, tartós bejáratot nyithatna a panelbe. Staff social belépése a verifikált e-mail-egyezésen át működik (§3), linkelés nélkül.
  - **Jelszavas ügyfél** a kapcsolás előtt újra megadja a jelszavát (`/user/confirm-password`, `auth.password_timeout`).
  - **Minden kapcsolásról és leválasztásról levél megy** a fiók címére (`SocialAccountChangedNotification`, platformlevelek: `social_account_linked`, `social_account_unlinked`) — ez az egyetlen jel, ami akkor is eléri a tulajt, ha nem ő ült a session előtt. Jelszó nélküli ügyfélnél ez az egyetlen védvonal (nincs mit újra megadnia).
- Itt **nincs e-mail-szabály**: a user már hitelesített, az identitást pedig a providernél, ugyanebben a böngészőben igazolja. Így e-mail nélküli Facebook-fiók is kapcsolható.
- Elutasítás: a identitás **már más fiókhoz** tartozik → `identity_taken` (az áthelyezés átadná a másik fiók belépését); a fióknak **már van** fiókja ennél a providernél → `provider_already_linked`.
- **Leválasztás:** `DELETE /my/social-accounts/{id}` — `SocialAccountPolicy` (csak a sajátját; másét **404**-gyel, tenanton belül és kívül is — a FormRequest `authorize()` a policy `Response`-át adja vissza, különben a 404 403-má esne össze), `UnlinkSocialAccountRequest`: az **utolsó belépési mód** (nincs jelszó és nincs másik provider) nem választható le → `last_sign_in_method`. A controller ugyanezt a user sorára tett zár alatt újraellenőrzi: két egyszerre küldött leválasztás (Google + Facebook) különben mindkettő „a másik marad”-ot látná.

### 5.3 Provider e-mail nélkül (Facebook telefonos fiók)

1. A consume a feloldatlan identitást a **session**be teszi (`social_login.pending`, 30 perc, tenanthoz kötve), és a `/auth/social/email` oldalra visz.
2. A user megad egy címet → `SocialEmailConfirmationNotification` megy rá egy egyszer használható, 30 perces tokennel (`SocialAuthBroker::issueEmailConfirmation`). A cím létezéséről ekkor **semmit** nem mondunk. ⚠️ A form bármely címre slot4u-aláírású levelet küld, ezért kétszeresen korlátozott: **kísérletenként 3**, **címenként 3 / óra** (`too_many_emails`) — egy telefonos Facebook-fiók ingyen van, az IP cserélhető.
3. A levél linkje (`/auth/social/email/confirm?token=`) **csak ugyanabban a böngészőben** működik: a token a pending attempt id-jához és a megadott címhez kötött, és mindkettőnek egyeznie kell a sessionben lévővel. A link a session **egyezése után** ég el (a sessionben csak a token hash-e van): egy levélszkenner (Outlook Safe Links, céges gateway), ami session nélkül előre lekéri, nem teszi használhatatlanná. Utána a feloldás a megerősített címmel, **verifikáltként** fut tovább (§3), a flow eredeti intentjével és return path-jával.

⚠️ **Miért ugyanaz a böngésző.** Enélkül ez a lépés fiókátvétel: a támadó egy e-mail nélküli Facebook-fiókkal az **áldozat** címét adja meg, az áldozat valódi „erősítsd meg” levelet kap, rákattint — és a támadó Facebook-identitása az áldozat fiókjához kapcsolódik. Sessionhöz kötve az áldozat kattintása semmit nem erősít meg (a pending attempt a támadó sessionjében van). Ára: más eszközön megnyitott link nem működik, ezt a levél és az oldal is kimondja.

A levél szövege a superadminban szerkeszthető platformlevél (`social_email_confirmation`, docs/27). Mobilon a levelezőapp beépített böngészője is „másik böngésző” — ezt a levél és az oldal kimondja.

## 6. Adatmodell és GDPR

`social_accounts` — docs/02. `BelongsToTenant` (a callback tenant nélkül fut, ott a scope no-op; a lekérdezések ettől függetlenül `withoutGlobalScopes`-szal, explicit feltétellel mennek).

- **Export:** `subject.linked_accounts` (provider, provider_user_id, email, név, avatar, kapcsolás ideje).
- **Törlés/anonimizálás:** a sorok törlődnek (`AnonymizeUserProfile`), a sweep-teszt ezt ellenőrzi.
- **Tenant-purge:** az anonimizáláson át, és FK-cascade a tenantra.

### 6.1 Meta „User data deletion” (SLO-253)

A Meta akkor hívja, amikor valaki a Facebook-beállításaiban eltávolítja az appot. **Enélkül az app nem kapcsolható Live módba.**

| Végpont | Mit csinál |
|---|---|
| `POST https://slot4u.hu/auth/facebook/data-deletion` | a Meta „Data deletion callback URL”-je — csak a központi domainen, CSRF-mentes (`bootstrap/app.php`), `throttle:webhook` |
| `GET https://slot4u.hu/facebook/data-deletion` | instrukciós oldal (a Meta „Data deletion instructions URL”-jébe is ez írható) |
| `GET https://slot4u.hu/facebook/data-deletion/{code}` | a callback válaszában visszaadott státuszoldal |

- **Hitelesítés:** a `signed_request` = `base64url(aláírás).base64url(payload)`, az aláírás a **kódolt** payload HMAC-SHA256-ja az app secrettel (`FacebookSignedRequest`, `hash_equals`). Az `algorithm` mezőnek is `HMAC-SHA256`-nak kell lennie. Bármi más → `400 {"error":"invalid_signed_request"}`, és semmi nem íródik. Konfigurálatlan Facebook → 404.
- **Mit töröl** (Daniel döntése, SLO-250): az adott Facebook-azonosító **minden** `social_accounts` sorát (minden cégnél) — vele a tárolt FB-azonosítót, nevet, e-mailt, avatar-hivatkozást. **Nem** törli az ügyfélfiókot és a foglalásokat: azoknak a vállalkozás az adatkezelője (docs/19 §1). A státusz- és az instrukciós oldal ezt kimondja, és a teljes törléshez a members area adatvédelmi oldalára (vagy a vállalkozáshoz) irányít. Ha a fióknak így nem marad belépési módja, jelszót az „Elfelejtetted a jelszavad?” úton állíthat be.
- **Válasz:** `{"url": "https://slot4u.hu/facebook/data-deletion/{code}", "confirmation_code": "{code}"}` — a kód 20 karakteres, nagybetűs.
- **Nyilvántartás:** `social_data_deletion_requests` (docs/02) — a Facebook-azonosító csak **sha256 hash**-ként, a törölt sorok száma, időpont. Ismeretlen vagy ismételt azonosítóra is kerül sor és kód („nem tároltunk semmit” is végleges válasz). A tábla platformszintű, nem tenant-adat.

## 7. Konfiguráció

### 7.1 `.env`

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
```

Ha egy providernél bármelyik üres, az a provider **ki van kapcsolva**: nincs gomb, a végpontjai 404-et adnak. Dev és CI így kulcs nélkül is működik.

A redirect URI-t **nem** kell env-ben megadni: mindig `{APP_URL sémája}://{APP_CENTRAL_DOMAIN}/auth/{provider}/callback` (`SocialAuthUrls::callback`).

### 7.2 Google Cloud Console

1. APIs & Services → OAuth consent screen: External, app neve „slot4u”, támogatási e-mail, **Authorized domains:** `slot4u.hu`, adatvédelmi és ÁSZF link. Scope-ok: `openid`, `email`, `profile` (nem érzékenyek, nem kell hitelesítési eljárás).
2. Credentials → Create OAuth client ID → **Web application**.
3. **Authorized redirect URIs:** `https://slot4u.hu/auth/google/callback`. (Authorized JavaScript origins nem kell.)
4. A kapott Client ID / Client secret → a szerver `.env`-je.
5. Amíg a consent screen „Testing” módban van, csak a felvett tesztfelhasználók léphetnek be → élesítés előtt „In production”.

### 7.3 Meta for Developers

1. Create app → „Authenticate and request data from users with Facebook Login”.
2. Facebook Login → Settings: **Valid OAuth Redirect URIs:** `https://slot4u.hu/auth/facebook/callback`; Client OAuth login és Web OAuth login bekapcsolva, „Enforce HTTPS” be.
3. App settings → Basic: **App Domains:** `slot4u.hu`, Privacy Policy URL, Terms URL, kategória, ikon; az App ID / App secret → a szerver `.env`-je.
4. Permissions: `email`, `public_profile` (alapból elérhetők, App Review nem kell hozzájuk).
5. **Settings → Advanced / App settings → Basic → „User data deletion”:** válaszd a „Data deletion callback URL”-t, értéke `https://slot4u.hu/auth/facebook/data-deletion` (§6.1). Az instrukciós oldal: `https://slot4u.hu/facebook/data-deletion`. **Enélkül az app nem kapcsolható Live módba**, addig csak az app szerepkörrel rendelkező (admin/developer/tester) fiókok léphetnek be.
6. App Review nem kell (`email`, `public_profile`), utána az app **Live** módba kapcsolható.

### 7.4 Lokális fejlesztés

A Google `http://` redirect URI-t csak `localhost`-ra fogad el, a `slot4u.test` nem regisztrálható. A lokális ellenőrzés ezért a Pest-tesztekre támaszkodik (`Socialite::fake`, minden más valódi: flow, nonce, token, feloldás). Valódi végponttól-végpontig próba élesben vagy stagingen (SLO-156).

## 8. Deploy (Tárhely.Eu)

- A `composer.lock` változik (Socialite) → a `deploy.sh` futtat `composer install --no-dev`-et. A Socialite `require`, nem `require-dev`.
- Migrációk: `social_accounts` létrehozása, `users.password` nullable (SLO-251), `social_data_deletion_requests` (SLO-253).
- A kulcsokat a **szerver** `.env`-jébe kell írni (`~/slot4u/.env`), utána `php artisan optimize:clear` (ea-php84: `/opt/cpanel/ea-php84/root/usr/bin/php`), mert a config cache-elt.
- Kulcs nélkül a release biztonságos: a gombok egyszerűen nem jelennek meg.

## 9. Tesztek

`tests/Feature/Auth/SocialLoginTest.php` — ügyfél-regisztráció, e-mail link, két provider = egy user, kapcsolt identitás e-mail-váltás után, admin/staff belépés, **admin/staff létrehozás tiltása** (központi domain, szerep-paraméter), superadmin tiltás, admin host, 2FA, verifikálatlan e-mail, FB e-mail nélkül, másik tenant usere, pre-hijack, megszakított hozzájárulás, lejárt/újrajátszott state, konfigurálatlan provider, felfüggesztett tenant, **token egyszeri használat, lejárat, login-CSRF, tenant-csere**, return path (adatkészlettel), saját domain, jelszó nélküli login-üzenet, tenant-izoláció, felkínált gombok. Export: `MyPrivacyTest`, törlés: `PersonalDataErasureTest` (sweep).

`tests/Feature/Auth/FacebookDataDeletionTest.php` (SLO-253) — a Facebook-azonosító sorai törlődnek (másik provider ugyanazzal az id-val, másik FB-user és a fiók marad), válasz url + kód, ismeretlen id-ra is kód, **hamis secret / meghamisított payload / más algoritmus / hiányzó user_id / szemét → 400 és semmi nem törlődik**, parser-határesetek, konfigurálatlan Facebook, csak központi domain, CSRF-mentesség, státusz- és instrukciós oldal.

`tests/Feature/Auth/SocialLinkingTest.php` (SLO-252) — gombok csak vendégnek, visszatérés ugyanarra a lépésre, elutasítás a foglalási lépésen, **vendég-előtöltés más cég ügyfelének címével** (és login intentnél nem), kapcsolás e-mail nélküli identitással, vendég link-kísérlet, **másik fiók identitása**, második identitás ugyanannál a providernél, **közben kicserélt belépett user**, profil-propok, leválasztás jelszóval / másik providerrel, **utolsó belépési mód**, **más ügyfél fiókja 404** (tenanton belül és kívül), első jelszó beállítása és a jelenlegi jelszó megkövetelése, e-mail-lépés: oldal csak függő kísérlettel, regisztráció megerősítés után, meglévő fiókhoz kapcsolás, **másik böngészőben megnyitott link (átvétel-védelem)**, egyszeri link, lecserélt cím, lejárat, validáció, foglalás folytatása.

A biztonsági ágak mindegyikén mutációs próba futott: a feltétel kikapcsolására a hozzá tartozó teszt pirosra vált.
