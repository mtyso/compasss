# CLAUDE.md — Компас

Personal app of the owner (Elyor) for money, habits and tasks, with a rule-based financial advisor at its core.
The owner communicates in **Russian**; UI is **Russian and Uzbek (Latin)**; code, comments, table names and commits are in **English**.

Read `docs/PLAN.md` first: it holds the numbered decisions (D1…D18), the advisor design, the data model and the stage plan.
When the plan and the code disagree, fix whichever is wrong and update the decision.

## Layout

- `server/` — Laravel 13 API, PHP 8.4. Production DB is MariaDB 11.4 on AHost shared hosting (cPanel).
- `app/` — Vue 3 + TypeScript + Vite + Tailwind 4 SPA. The same build is the web app and, through Capacitor, the Android app.
- `deploy/` — release build and server update scripts (same model as `mtyso/emerich-dealers`, `docs/ops/DEPLOY_CPANEL.md` there).
- `docs/` — plan and decisions.

## Local commands

Server (from `server/`):

```sh
composer setup          # first run: install, .env, key, sqlite file, migrations
php artisan serve       # API and built SPA on http://127.0.0.1:8000
vendor/bin/pest         # tests (sqlite in memory; CI runs them on MariaDB 11.4)
php artisan login:approve   # confirm a pending app sign-in without the bot (needs ALLOWED_TELEGRAM_IDS)
```

App (from `app/`):

```sh
npm install
npm run dev             # Vite on http://localhost:5173, proxies /api to 127.0.0.1:8000
npm run typecheck
npm test                # vitest
npm run build           # app/dist; deploy copies it into server/public
```

Production runs PHP 8.4; `composer.json` still allows 8.3 so the sandbox and older local PHP can install.

## Hosting constraints (AHost) that shape the code

- No root, no composer, no node, no long-running processes. One cron per minute runs `schedule:run`; the database queue is drained from the schedule with `--stop-when-empty`.
- Sessions and cache live in the database.
- CLI PHP is `/opt/alt/php84/usr/bin/php`.
- The host's anti-bot WAF can answer JSON routes and the Telegram webhook with a challenge page. Keep all JSON under `/api/*` and the webhook at `/telegram/webhook` so they can be excluded in one ticket.

## Conventions

- Money: integers in minor units (UZS without tiyin, USD in cents) plus `currency`; totals in UZS via `amount_uzs`. Rates are `decimal(18,4)`; use `bcmath`, never float.
- Dates: compare with `whereDate()` or `date` columns. SQLite (local tests) and MariaDB (production, CI) differ on datetime strings.
- Time zone `Asia/Tashkent`; UI dates `dd.mm.yyyy`.
- Every user-owned table has `user_id` even though there is one user today.
- Soft delete for transactions, tasks and habits.
- No paid services or APIs. Telegram Bot API, the CBU rates JSON and Firebase are fine.
- Advisor rules are pure classes under `App\Advisor\Rules`, one rule per class, each covered by tests on example data.
- Small commits with clear English messages (`feat: daily spending limit`).
