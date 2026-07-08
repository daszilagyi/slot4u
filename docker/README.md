# slot4u — fejlesztői környezet (WSL2 + Docker Compose)

Prod-azonos lokális stack: **PHP-FPM 8.3, nginx, MariaDB 11, Redis 7, Reverb, Horizon, Vite**.
A `CLAUDE.md` döntése szerint a repo a **WSL2 Linux fájlrendszerén** él (pl. `~/dev/slot4u-app`),
**nem** `/mnt/c/...` Windows mounton — a bind-mount IO teljesítménye miatt.

## 0. Egyszeri előfeltételek (Windows host — neked kell)

```powershell
# 1) WSL2 + Ubuntu distro (PowerShell, rendszergazdaként; gép újraindítást kérhet)
wsl --install -d Ubuntu

# 2) Docker Desktop indítása, majd: Settings → Resources → WSL Integration → Ubuntu BE
```

## 1. Repo a WSL fájlrendszerére

Ubuntu shellben (NEM /mnt/c alatt):

```bash
mkdir -p ~/dev && cd ~/dev
# a Windows scaffoldból átmásolva (a git history-val együtt):
git clone /mnt/c/Users/daszi/dev/slot4u-app ~/dev/slot4u-app
cd ~/dev/slot4u-app
```

## 2. Környezet + stack indítás

```bash
cp .env.docker.example .env
# a fájljogokhoz igazítsd: sed -i "s/^UID=.*/UID=$(id -u)/;s/^GID=.*/GID=$(id -g)/" .env

make build       # image-ek
make up          # stack háttérben
make install     # composer install + npm install
make key         # APP_KEY
make migrate     # MariaDB migrációk
```

## 3. Elérés

| Szolgáltatás | URL / port |
|---|---|
| App (nginx) | http://slot4u.test (és `*.slot4u.test` tenant subdomainek) |
| Vite HMR | http://localhost:5173 |
| Inertia SSR | belső (`ssr:13714`) — publikus oldalak szerver-renderelése (SEO) |
| Reverb (WS) | ws://localhost:8080 (csak `--profile workers` után) |
| MariaDB | localhost:3306 (`slot4u` / `secret`) |
| Redis | localhost:6379 |

A subdomain-alapú tenancy lokális teszteléséhez a Windows `hosts` fájlba
(`C:\Windows\System32\drivers\etc\hosts`) vedd fel pl.:

```
127.0.0.1 slot4u.test admin.slot4u.test demo.slot4u.test
```

(Alternatíva hosts-szerkesztés nélkül: `lvh.me` wildcard, `*.lvh.me` → 127.0.0.1.)

### Inertia SSR (publikus oldalak)

Az `ssr` szolgáltatás a buildelt bundle-t (`bootstrap/ssr/ssr.js`) futtatja node-dal a
13714 porton; a php-fpm az `INERTIA_SSR_URL=http://ssr:13714` címen éri el (lásd
`.env.example`). **Hot (Vite dev) módban** — amíg `public/hot` létezik — az SSR-t a Vite
dev szerver szolgálja ki, nem a bundle; a bundle-utat a nélküli („production") mód használja.
A bundle frontend-változás után újraépítendő, és az `ssr` konténer újraindítandó:

```bash
docker compose exec vite npm run build
docker compose restart ssr
```

Hiányzó/elavult bundle vagy leállt renderer esetén az app csendben kliens-oldali
renderelésre esik vissza (`INERTIA_SSR_THROW_ON_ERROR=false`).

## 4. Laravel Boost MCP a konténerben

A `boost:mcp` a konténer PHP 8.3-jával fusson (nem a Windows XAMPP PHP-vel). A `.mcp.json`
`laravel-boost` bejegyzését a WSL/Docker indítás után frissítjük (SLO-62 acceptance criteria).

## Gyakori parancsok

`make help` — teljes lista. `make sh` (shell), `make migrate`, `make test`, `make pint`, `make stan`, `make fresh`.

## Horizon + Reverb (workers profile)

A `horizon` és `reverb` szolgáltatások a `laravel/horizon` ill. `laravel/reverb` csomagok
telepítéséig (SLO-8 utáni M1 lépés) opt-in profil mögött vannak, hogy ne crash-loopoljanak:

```bash
docker compose --profile workers up -d   # miután a csomagok telepítve vannak
```
