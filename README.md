# Pixelforge Studio

Take-home assessment for PromptEdit: a Laravel + Vue + Inertia app where signed-in users generate images through the [Pixelforge API](https://pixelforge.promptedit.dev/docs) and are charged the exact provider cost plus a 25% markup.

## Live app

**https://pixelforge-studio-production-bbwi5k.laravel.cloud** — register an account (no email verification needed) and open the Studio.

Deployed on Laravel Cloud: MySQL 8.4, a queue worker (`queue:work database`) and the scheduler (runs `generations:reconcile` every minute). It uses its own Pixelforge API key, so `GET /v1/usage` for that key matches the app's records exactly.

## Status

Required part done: generate with a live price estimate, exact cost per generation (failed ones free), live history, verified and deduplicated webhooks, polling fallback, deployed. Stretch goals come next.

## Docs

- [docs/pixelforge-api-notes.md](docs/pixelforge-api-notes.md) — what the Pixelforge API does and what it means for this app.
- [docs/decisions.md](docs/decisions.md) — design choices and the reasons.

## Stack

Laravel 13, Inertia 3, Vue 3 (TypeScript), Tailwind, Pest. SQLite locally.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

Sign in at `/login` (run `php artisan db:seed` for a local test user) and open the Studio.

Then set these in `.env` (create a key in the Pixelforge dashboard):

```
PIXELFORGE_BASE_URL=https://pixelforge.promptedit.dev/v1
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Webhooks need a public URL. For local work, run a tunnel (Expose or ngrok) and set the tunnel URL on the key in the Pixelforge dashboard.

## Tests

```bash
php artisan test
```

The suite also passes against MySQL and Postgres (`DB_CONNECTION=mysql|pgsql …`).

## Running locally

`composer run dev` starts the web server, a queue worker and Vite. Without a queue worker nothing is sent to Pixelforge. Run `php artisan schedule:work` too if you want the polling fallback.

## Build phases

1. Money and pricing core (integer nanodollars, cost calculator)
2. Pixelforge API client with typed errors
3. Data model: generations, webhook events, credit ledger
4. Generate flow: queued submit job with retries
5. Webhooks: signature check, dedupe, out-of-order safety
6. Polling fallback
7. UI: generate page with live estimate, history with live status
8. Deploy
9. Stretch: balances and "Buy credits"
10. Stretch: admin report checked against `GET /v1/usage`
