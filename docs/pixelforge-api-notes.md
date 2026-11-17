# Pixelforge API — study notes

Source: <https://pixelforge.promptedit.dev/docs> and the live `GET /v1/pricing` response (read 2026-11-17).
These notes are the short version, with what each rule means for our app.

## 1. Basics

- Base URL: `https://pixelforge.promptedit.dev/v1`
- Auth: `Authorization: Bearer pf_…`
- All bodies are JSON. Timestamps are ISO 8601 UTC.
- Ids: `gen_…` for generations, `evt_…` for webhook events.
- Money is always a decimal **string** (`"0.046125000"`), never a float.
- **The API never returns a price.** It returns token counts. We calculate the price.

## 2. Endpoints

| Method | Path                | Auth | What it does                                             |
| ------ | ------------------- | ---- | -------------------------------------------------------- |
| POST   | `/generations`      | yes  | Start a generation. Returns `202` with status `queued`.  |
| GET    | `/generations/{id}` | yes  | Read one generation (polling).                           |
| GET    | `/generations`      | yes  | List, newest first. `limit` (1–100), `status`, `cursor`. |
| GET    | `/pricing`          | no   | Current rates and token rules.                           |
| GET    | `/usage`            | yes  | What Pixelforge billed **this API key**, per UTC day.    |

### Create a generation — parameters

| Name               | Rules                                                                                                             |
| ------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `prompt`           | required, max 4,000 characters                                                                                    |
| `size`             | `1024x1024` (default), `1536x1024`, `1024x1536`                                                                   |
| `quality`          | `low`, `medium` (default), `high`                                                                                 |
| `reference_images` | optional, max 4 http(s) URLs, each billed as 765 input image tokens                                               |
| `metadata`         | optional, string → string, max 20 keys, values max 500 chars. Echoed back on the generation and in every webhook. |

Unknown parameters are **rejected** (`422`), not ignored.

### Lifecycle

`queued` → `processing` → `succeeded` or `failed`

- A few seconds in the queue, then 5–30 seconds processing.
- `succeeded` and `failed` are final. They never change.
- `usage` is `null` until `succeeded`.
- `error` is `null` unless `failed`. Codes: `generation_failed`, `internal_error`.
- `rates` on the generation are fixed at creation time.

## 3. Pricing

Rates are US dollars per 1,000,000 tokens.

| Token type  | Rate   | Do we know the count before submit?                                 |
| ----------- | ------ | ------------------------------------------------------------------- |
| Input text  | $5.00  | **No.** Estimate `ceil(characters / 4)`. Real count is within ±15%. |
| Input image | $10.00 | Yes. Exactly 765 per reference image.                               |
| Output      | $40.00 | Yes. Fixed by quality and size (table below).                       |
| Reasoning   | $12.50 | **No.** A range by quality (table below).                           |

Characters means Unicode characters, not bytes → `mb_strlen`, not `strlen`.

Output tokens (exact):

| Quality | 1024x1024 | 1536x1024 | 1024x1536 |
| ------- | --------- | --------- | --------- |
| low     | 280       | 420       | 420       |
| medium  | 1,100     | 1,650     | 1,650     |
| high    | 4,300     | 6,450     | 6,450     |

Reasoning tokens (range):

| Quality | Min   | Max   |
| ------- | ----- | ----- |
| low     | 0     | 500   |
| medium  | 200   | 2,000 |
| high    | 1,000 | 8,000 |

Rules that matter:

- Only `succeeded` generations are billed. Failed and rejected ones are free.
- Rates can change. Read them from `GET /v1/pricing`; do not hardcode.
- For the **actual** cost use the `rates` on the generation itself (fixed at creation).
- Costs are exact and never rounded. Floats drift. Use integers (nanodollars) or decimals.
- A generation is billed on the **UTC day it completed**, not the day it was created.

Worked example from the docs (the most a generation can cost) — high, 1536x1024, 400-character prompt, 2 reference images:

| Tokens                 | Count | Rate   | Cost            |
| ---------------------- | ----- | ------ | --------------- |
| Input text (100 + 15%) | 115   | $5.00  | 0.000575000     |
| Input image (2 × 765)  | 1,530 | $10.00 | 0.015300000     |
| Output                 | 6,450 | $40.00 | 0.258000000     |
| Reasoning (max)        | 8,000 | $12.50 | 0.100000000     |
| **Total**              |       |        | **0.373875000** |

This becomes a unit test.

## 4. Usage endpoint

`GET /v1/usage?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`

- Per API key. Our deployed app must use its own key so numbers match exactly.
- Default range: last 30 days. Max 92 days (`422 date_range_too_long`).
- `cost_usd` has nine decimal places, unrounded.
- Days with no usage are included as zeros.
- This is the source of truth for the admin report's provider cost.

## 5. Errors

Same shape every time: `{ "error": { "type", "code", "message", "param"?, "errors"? } }`. Branch on `code`.

| Code                         | Status | What we do                                                                         |
| ---------------------------- | ------ | ---------------------------------------------------------------------------------- |
| `invalid_api_key`            | 401    | Config problem. Fail the generation, alert in logs. Do not retry.                  |
| `validation_failed`          | 422    | Our bug or bad input. Fail, show message. Do not retry.                            |
| `content_policy_violation`   | 400    | Prompt rejected. Fail, tell the user to change the prompt. Do not retry. Free.     |
| `not_found`                  | 404    | Wrong id or other account.                                                         |
| `idempotency_key_reused`     | 409    | Same key, different body. Our bug. Do not retry.                                   |
| `rate_limit_exceeded`        | 429    | Wait `Retry-After` seconds, retry.                                                 |
| `concurrency_limit_exceeded` | 429    | 3 already running. Wait (`Retry-After: 5`), retry.                                 |
| `daily_limit_exceeded`       | 429    | 500/day used up. Fail with a clear message. Retrying today will not help.          |
| `overloaded`                 | 503    | Nothing was created. Wait (`Retry-After: 2`), retry with the same idempotency key. |

## 6. Limits (per account, across all keys)

| Limit                  | Value                          |
| ---------------------- | ------------------------------ |
| Requests               | 60 per minute                  |
| Concurrent generations | 3 queued or processing at once |
| Generations per day    | 500 per UTC day                |

- Every response has `X-RateLimit-Limit` and `X-RateLimit-Remaining`.
- Respect `Retry-After`. Back off more each time. Give up after a few attempts.
- Every retry counts against the per-minute limit. Polling counts too.
- Limits are per **account**, so local and production keys share them.

## 7. Idempotency

- Send `Idempotency-Key` on `POST /generations` (any unique string, max 255 chars).
- Same key + same body → the same generation comes back (`202`, header `Idempotent-Replayed: true`). Nothing new is created or billed.
- Same key + different body → `409 idempotency_key_reused`.
- Keys never expire.

For us: one UUID per local generation row, created before the first call. Every retry reuses it.

## 8. Webhooks

Each API key has its own webhook URL and its own signing secret (`whsec_…`).

| Event                   | When                                  |
| ----------------------- | ------------------------------------- |
| `generation.processing` | left the queue                        |
| `generation.succeeded`  | finished, has `usage` and `image_url` |
| `generation.failed`     | failed, has `error`                   |
| `ping`                  | "Send test event" in the dashboard    |

Body: `{ id: "evt_…", type, created_at, data: { object: <full generation object> } }`

Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature`.

### Verifying the signature

- Header: `Pixelforge-Signature: t=<unix timestamp>,v1=<hex>`
- Expected value: `hash_hmac('sha256', "<t>.<raw body>", <signing secret>)`
- Use the **raw** body, before JSON decoding.
- Compare with `hash_equals` (constant time).
- Reject when the timestamp is more than 300 seconds old (replay protection).
- The route must be outside CSRF protection.

### Delivery rules

- Any `2xx` within 10 seconds = delivered. Anything else is retried after 10s, 30s, 2m, 10m, 15m (6 attempts in total). Redirects are not followed.
- **At least once**: the same event can arrive more than once → dedupe by event id.
- **Any order**: a late `generation.processing` can arrive after `generation.succeeded` → never move back from a final status.
- A webhook can arrive **before** our `POST /generations` call has returned.
- Delivery is not guaranteed → keep polling `GET /generations/{id}` as a fallback.
- Webhook URL must be public. Local development needs a tunnel (Expose, ngrok).

## 9. Images

- `image_url` is a signed PNG URL. It stops working 24 hours after completion (`image_url_expires_at`).
- The assessment says it is fine if images stop loading after that.

## 10. Test keywords

Put these anywhere in the prompt (case-insensitive; they still count as prompt characters):

| Keyword        | Behaviour                                |
| -------------- | ---------------------------------------- |
| `[blocked]`    | `400 content_policy_violation` at submit |
| `[overloaded]` | `503 overloaded` at submit               |
| `[fail]`       | accepted, then fails while processing    |
| `[succeed]`    | accepted, never fails at random          |
| `[slow]`       | takes 60–90 seconds                      |
| `[duplicate]`  | every webhook event is delivered twice   |

Without keywords Pixelforge still fails at random sometimes: random failures, random `503`, random duplicate webhooks.
