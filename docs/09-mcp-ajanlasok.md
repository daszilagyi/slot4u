# MCP ajánlások — slot4u (2026)

A slot4u stackhez (Laravel 12 + Inertia v2 + React + TS + Tailwind 4 + shadcn/ui) illesztett MCP-k, prioritás szerint.

## Tier 1 — kötelező (azonnal telepítendő)

| MCP | Réteg | Miért |
|---|---|---|
| **Laravel Boost** (hivatalos, `laravel/boost` dev dependency) | Backend | 15+ tool: tinker, log-olvasás, DB query, config, artisan; 17 000+ elemű Laravel docs API szemantikus kereséssel. A legnagyobb hatású MCP ehhez a projekthez. |
| **Context7** | Backend + Frontend | Verzió-pontos doksi (Laravel 12, Inertia v2, Tailwind 4, React) — megszünteti az elavult/hallucinált API-használatot. Tailwind 4-nél kritikus, mert sok AI még v3-as szintaxist ír. |
| **shadcn MCP** (hivatalos, `ui.shadcn.com/docs/mcp`) | UI | Registry-böngészés és komponens-telepítés természetes nyelven; konzisztens shadcn/Radix komponenshasználat. |
| **Semgrep** (plugin: MCP + hooks + skills egyben) | Security | Code + Supply Chain + Secrets szken minden generált fájlra. Fontos: a régi `semgrep/mcp` repo deprecated — a hivatalos `semgrep` binárison / Semgrep pluginen keresztül telepítsd. Multi-tenant SaaS-nál (tenant-izoláció, auth) különösen indokolt. |

## Tier 2 — erősen ajánlott

| MCP | Réteg | Miért |
|---|---|---|
| **Playwright MCP** (Microsoft, hivatalos) | UX / QA | Accessibility-snapshot alapú böngészővezérlés (cross-browser). A foglalási flow-k E2E ellenőrzése + a11y validáció — 2026-ban az accessibility-first UX alap-trend. Token-spóroláshoz: Playwright CLI variáns (~4x kevesebb token). |
| **Chrome DevTools MCP** (Google, hivatalos) | UX / Performance | Lighthouse-jellegű audit, Core Web Vitals, network/console debug — a publikus foglalófelület sebessége konverziókritikus. |
| **GitHub MCP** | Workflow | PR-ok, issue-k, CI futások kezelése. (Linear MCP már csatlakoztatva van ebben a környezetben — az issue-workflow lefedett.) |

## Tier 3 — opcionális / később

| MCP | Mikor |
|---|---|
| **OpenMemory (mem0)** | Ha több AI-klienst (Cursor, Claude Code, Cowork) használsz párhuzamosan és közös, lokális memóriaréteg kell. Local-first, Postgres + Qdrant, audit log. Megjegyzés: a Cowork-nek van beépített perzisztens memóriája — egy kliensnél az OpenMemory redundáns. |
| **Figma Dev Mode MCP** | Csak ha a UI-tervek Figmában készülnek — design-to-code a valós layer-struktúrából, nem screenshotból. |
| **Sentry MCP** | Production után: hibák breadcrumb-okkal, környezeti kontextussal közvetlenül az agentnek. |
| **Stripe MCP** | A fizetési integráció fázisában (`docs/08-integraciok-roadmap.md`). |

## Biztonsági figyelmeztetés

2026 áprilisában az OX Security RCE-sérülékenységet tárt fel az MCP SDK stdio transportjában (minden nyelvi SDK érintett volt). Tanulság: csak hivatalos, karbantartott MCP-ket telepíts (Laravel, Microsoft, Google, Semgrep, shadcn), tartsd frissen őket, és kerüld a random közösségi szervereket — főleg, amelyik írási joggal fér a DB-hez.

## Ajánlott sorrend

1. Laravel Boost + Context7 (fejlesztési alap)
2. shadcn MCP (UI konzisztencia)
3. Semgrep plugin (minden commit előtt szken)
4. Playwright MCP (amint van mit tesztelni — foglalási flow-k)
5. Chrome DevTools MCP (performance-hangolás fázisban)

## Források

- https://github.com/laravel/boost · https://laravel.com/docs/12.x/boost
- https://ui.shadcn.com/docs/mcp
- https://semgrep.dev/docs/mcp
- https://mem0.ai/openmemory
- https://playwright.dev/docs/getting-started-mcp
- https://www.builder.io/blog/best-mcp-servers-2026
- https://stevekinney.com/writing/driving-vs-debugging-the-browser
