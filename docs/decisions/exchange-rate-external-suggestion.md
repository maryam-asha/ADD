# External exchange-rate suggestions (sp-today): a suggestion, never an authority

**Status:** resolved 2026-08-25. **Owner:** Maryam Asha.

## What changed

`exchange_rates` gains a way for an admin to see a third-party quote (sp-today)
next to the current rate and, if they choose, accept it with one click instead
of retyping a number by hand. The external source never writes `exchange_rates`
itself, never runs inside a request cycle, and never bypasses the admin who
clicks accept — see the Guard section.

## Decision

- **A suggestion, not an input.** `exchange_rates` stays append-only, one row
  per explicit admin action, `set_by` always the authenticated admin. A new
  `exchange_rate_suggestions` table holds candidate values; nothing in
  `exchange_rates` changes shape except two additive columns (`source`,
  `suggestion_id`) recording whether a row originated from a manual entry or
  from an accepted suggestion.
- **The scheduled command is the only thing that talks to sp-today.** No
  controller, request, middleware, or resource may reference
  `SpTodayRateClient` — enforced by a new guard test (below). This mirrors why
  `exchange_rates` itself is never written outside the admin path: an external
  call inside a request cycle, or a write path a request could reach, is
  exactly the shape of thing that turns a vendor hiccup into money moving
  without a human in the loop.

### Phase 0 findings — the vendor contract, as verified against a live call, not as assumed

The spec handed to this work named an endpoint and a city slug that turned out
not to exist. Both were corrected against the real API before anything was
built:

| Assumed | Actual (verified `2026-08-25`, `GET` with `X-API-Key`) |
|---|---|
| `GET https://api-v2.sp-today.com/api-dashboard/currencies/{city}?lang=ar` | `GET https://api-v2.sp-today.com/api/v1/currencies` — the assumed path 404s; `/api-dashboard` is a *web login page*, not an API namespace. |
| A per-city path segment, `aleppo` if not corrected | No path/query city filter exists. The response embeds a fixed `cities` array — exactly two entries, `damascus` (labeled `"name_en":"General"`, `"name_ar":"سوريا - عام"` — a **nationwide** rate, not Damascus-specific) and `alhasakah`. **There is no Aleppo-specific rate anywhere in this vendor's data.** |
| Unconfirmed sell/buy field names | Confirmed exact: `buy` and `sell`, live under `data.currencies[] → {code, slug, name, name_ar, symbol, flag, cities: {damascus: {buy, sell, change, prev_close, day_high, day_low, change_week, change_month, change_year}, alhasakah: {...}}, updated_at}`. USD's entry has `code: "USD"`. Both `buy`/`sell` are raw JSON numbers (e.g. `13275`), never strings, never thousands-separated. |
| Unconfirmed quota-header names | Confirmed exact: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (unix epoch). |
| — | Response envelope: `{"ok": true, "data": {...}}`. `sell >= buy` held for every currency in the live sample (e.g. USD `buy:13225 / sell:13275`), so that guard's premise is sound — it was just never reachable for a city that doesn't exist. |

**Resolution on the missing city:** use `cities.damascus` — sp-today's
nationwide/general rate — as the suggestion source, and label it as
*nationwide* everywhere (code, DB comments, API field descriptions, this doc)
rather than implying it is Aleppo-specific. The feature's job is to save an
admin from retyping a number they'd otherwise have looked up themselves; a
nationwide USD/SYP quote is the same input they'd have used anyway. If sp-today
later exposes a real Aleppo feed, swapping the city slug is a one-line change
contained entirely to `SpTodayRateClient`.

**Endpoint chosen:** `GET /currencies`, not `/overview` — same per-currency
`buy`/`sell` shape, smaller payload, no unrelated gold/FX data to parse past.

### The direction problem — this is the part that would have been a real money bug

`exchange_rates.rate_to_base` stores **"units of the base currency (USD) per 1
unit of `currency_code`"** ([multi-currency-support.md](multi-currency-support.md)).
For `currency_code = 'SYP'` that's a tiny decimal — the existing factory's own
example is `0.0000680272` (≈ 1 ÷ 14,700).

sp-today's `sell` value is the opposite direction: **SYP per 1 USD** (e.g.
`13275` — "how many SYP to buy one dollar"). These are reciprocals of each
other, not the same number in different formatting.

- `exchange_rate_suggestions.rate_usd_to_syp` stores sp-today's number
  **as given** (SYP-per-USD, human-readable, matches how the number is quoted
  everywhere outside this codebase).
- `POST /admin/exchange-rates` is unchanged in what it accepts:
  `rate_to_base` is still the tiny USD-per-SYP decimal the admin (or the
  dashboard pre-filling the form) submits — this backend never inverts it.
  The only new behavior is *linking* that submission to the suggestion it
  came from.
- The one place both numbers meet is `deviation_percent`, which is
  display-only. It converts the current effective rate to the *same*
  direction as the suggestion before comparing —
  `current_syp_per_usd = 1 ÷ current.rate_to_base` — then
  `(rate_usd_to_syp − current_syp_per_usd) ÷ current_syp_per_usd × 100`.
  Comparing the two numbers without this conversion would produce a
  meaningless (and wildly large) percentage.

### Gaps between the original task text and the current codebase

Reported per the task's own rule — surfaced, not silently patched around:

- `App\Domain\Finance\Enums\Currency` does not exist; it was deleted in
  `d68b782` and replaced by `App\Domain\Finance\Models\Currency`, an
  admin-managed DB table ([multi-currency-support.md](multi-currency-support.md)).
  Nothing in this feature touches it either way, but the task text's
  prohibition against modifying it was aimed at a class that is already gone.
- There is no `audit_logs` table. Sensitive-action logging is
  spatie/activitylog's `activity()` helper via the `App\Concerns\LogsSensitiveActions`
  trait, writing to `activity_log`. This feature logs through that same trait,
  not a new mechanism.
- The task text described `POST /admin/exchange-rates`'s existing body as
  carrying `rate_usd_to_syp`. The real, current body is `currency_code` +
  `rate_to_base` + `effective_from` (post multi-currency-support.md rename).
  The "purely additive" instruction is honored against the *real* body: only
  `suggestion_id` is added, nothing already there changes shape.
- Two validation rules exist beyond the task text's explicit list, both
  necessary for the feature to be safe rather than merely functional:
  - When `suggestion_id` is present, `currency_code` must be `'SYP'` — a
    USD/SYP suggestion silently accepted against an unrelated currency code
    would write a nonsensical rate under `source = 'external_accepted'`.
  - The ingestion job also validates `buy` is numeric before comparing
    `sell >= buy`; a missing/non-numeric `buy` is treated as the same
    "response contract broke" failure as a missing `sell`, not as an
    automatic pass.

## Guard

New: `tests/Guards/SpTodayClientUsageIsScheduledOnlyTest.php` — fails if
`SpTodayRateClient` is referenced anywhere except the scheduled command
(`App\Console\Commands\FetchExchangeRateSuggestion`) and the ingestion service
it delegates to (`App\Domain\Finance\Services\ExchangeRateSuggestionIngestor`).

Existing, unchanged by this feature but load-bearing: `tests/Guards/NetworkIsolationTest.php`
(PRD decision #20 — no external host reference in `app/`, `database/`, or
`routes/`). This feature stays compliant by keeping the literal sp-today host
confined to `config/services.php` (read via `env('SP_TODAY_KEY')` /
`env('SPTODAY_BASE_URL', ...)`, both excluded from that guard's scan) and this
doc — `SpTodayRateClient` itself only ever reads `config('services.sptoday.*')`,
never a hardcoded URL.
