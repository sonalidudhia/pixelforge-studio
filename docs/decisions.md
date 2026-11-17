# Design decisions

The assessment leaves some details open. These are the choices made and why.
Status: planned. Each item is updated when its code lands.

## Money

1. **Integer nanodollars.** 1 USD = 1,000,000,000 nanodollars. A rate of `$5.00 per 1M tokens` is exactly 5,000 nanodollars per token, so `tokens × rate` is always a whole number. No floats anywhere. Stored as `bigint`. This is the same precision as Pixelforge's nine-decimal `cost_usd`. The calculator adds up `tokens × rate` for all four token types first and divides by 1,000,000 once; if a future rate ever left a fraction of a nanodollar, it rounds up. (`app/Support/Money.php`, `app/Pixelforge/CostCalculator.php`)
2. **Two amounts per generation.** `provider_cost` (what Pixelforge charges us) and `user_cost` (what we charge the user). They are stored separately so the admin report can show spend, cost and profit.
3. **25% markup, rounded up.** `user_cost = ceil(provider_cost × 1.25)` in nanodollars. Rounding up means we never charge less than cost plus markup. With today's rates the result is always a whole number anyway.
4. **Actual cost uses the generation's own `rates`.** Pixelforge fixes the rates when the generation is created. We store that snapshot and calculate from it, not from today's pricing.
5. **Failed generations cost zero.** Both amounts stay `0`.

## Estimate

6. **A range, with the maximum as the headline.** Text tokens (±15%) and reasoning tokens (range by quality) are unknown before the generation finishes. We show "at most $X" plus the lowest possible price. The real cost is always inside the range.
7. **Pricing comes from `GET /v1/pricing`**, cached for a short time. Nothing is hardcoded.

## State and webhooks

8. **Our row exists before we call Pixelforge.** We send our row id in `metadata` and a UUID as `Idempotency-Key`. A webhook that arrives before the POST returns still finds its row. A retry never creates a second generation.
9. **One function applies provider state.** Webhooks, polling and the POST response all pass the generation object to `ApplyGenerationSnapshot`. It runs in a transaction with a row lock and holds two rules: the status only moves forward (`GenerationStatus::canTransitionTo`), and the cost is set once, in the same save that makes the generation final. Skipping a step (queued → succeeded) is allowed, because a processing event can be lost.
9a. **A `pending` status of our own** means "saved, not yet accepted by Pixelforge". Until Pixelforge has accepted a generation, our status is only a guess, so anything Pixelforge says wins. This matters when we give up submitting after network errors but one attempt reached Pixelforge after all: its webhook turns our "failed" into the real result, so our costs still match `GET /v1/usage`.
10. **Webhook dedupe by event id.** A `webhook_events` table has a unique `event_id`. We insert first; if the row already exists we answer `200` and do nothing.
11. **Signature check on the raw body**, constant-time compare, 5 minute tolerance.

## Robustness

12. **Submitting runs in a queued job** (`SubmitGeneration`). The API client makes one attempt per call; the job owns all retrying, so attempts are never multiplied by two retry layers. On a busy answer (rate limit, concurrency limit, overload, 5xx, network error) it waits `max(Retry-After, backoff)` where backoff is 5, 10, 20, 40, then 60 seconds, for up to 8 attempts (about 4 minutes: enough for the 3-at-once limit to clear behind slow generations). Every attempt sends the same idempotency key. A permanent answer (blocked prompt, invalid input, daily quota, bad API key) fails at once. Either way the generation is marked failed with a plain-language message and costs nothing.
12a. **Our own submit endpoint is throttled** to 10 per user per minute, because all users share Pixelforge's 60 requests per minute.
13. **Polling fallback.** A scheduled command checks generations that are not final after a while, because webhook delivery is not guaranteed.
14. **Live status by Inertia polling.** The history page reloads its data every couple of seconds while something is active. No websockets needed for this size of app.

## Reporting and balances (stretch)

15. **Admin report groups by the provider's `completed_at` in UTC.** That is how Pixelforge bills, so daily totals match `GET /v1/usage`.
16. **Balance is a ledger.** `credit_transactions` rows: purchase, hold (maximum estimate), charge (actual), release. A unique index per generation and type makes a double charge impossible at database level.

## Environments

17. **One Pixelforge API key per environment** ("Local development", "Production"). Each has its own webhook URL, signing secret and usage report.
