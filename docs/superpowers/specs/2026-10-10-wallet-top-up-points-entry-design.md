# Wallet top-up: points-first entry, two-way conversion, minimum points

Date: 2026-10-10. Spans ADDCore (API) and ADDOS (dashboard).
Amends [Spec C §4](2026-09-12-add-point-c-wallet-design.md) (top-up flow) and
relaxes [system spec Invariant 4](2026-09-12-add-point-system-design.md) for
the top-up dialog only — see §3.

## 1. Why

Today reception types a cash amount and the server floors it into points.
Two problems:

1. **The remainder is swallowed silently.** 500,000 SYP buys 340.136 points;
   340 are credited, `payment_amount` records 500,000, and the ~200 SYP that
   bought nothing is visible to no one — not the operator, not the receipt.
2. **An over-credit bug.** `WalletTopUpController::paymentInBaseCurrency()`
   rounds the USD value half-up to 2 decimals (on floats) *before* the floor.
   501,200 SYP = $34.0952 → "34.10" → 341 points, worth more than was paid.
   Violates Invariants 2 and 5.

Typing points instead of money does not remove fractions by itself — 340
points cost 499,800.08 SYP, which is not payable in cash. The fraction has to
land somewhere; this design puts it in one explicit, visible place.

## 2. Decisions (agreed 2026-10-10)

- **Both entry modes, points first.** The dialog has linked *points* and
  *amount* fields. Points is the default.
- **Minimum top-up in points**, configurable: setting
  `wallet.top_up_min_points`, default **10**.
- **Conversion runs in the dashboard**, instantly, with no request per
  keystroke. The server stays the authority (§3).
- **Per-currency cash step**, editable on the Currencies page: new column
  `currencies.cash_step`, default **1 for every currency**.
  *Correction (2026-10-10, while planning):* SYP = 100 was agreed against
  the old-pound figures used in §1. SYP is the new pound (two zeros
  removed, in circulation since 2026-01-01 — see `WalletTopUpsPage.vue`),
  so one point is ~14.7 SYP and a step of 100 would add up to ~6.8 points
  to a top-up. 1 matches the dashboard's existing whole-pound rule; an
  admin raises it per currency if the till cannot give change that small.

The worked examples in §1 and the test vectors keep the old-pound rate
`0.0000680272` because ADDCore's existing tests and `ExchangeRateFactory`
use it; the arithmetic is rate-agnostic. §6 adds new-pound vectors.

## 3. The rule, and who enforces it

Let `R` = `point.rate_usd`, `F(c)` = current `rate_to_base` of currency `c`
(`F(base) = 1`), `S(c)` = `cash_step`.

- **`pointsFor(amount, c) = floor(amount × F(c) / R)`**, computed exactly —
  no intermediate rounding anywhere. This is the only rule that decides what
  gets credited, and the server applies it.
- **`amountFor(points, c)`** = the smallest multiple of `S(c)` that is
  `≥ points × R / F(c)`. Dashboard only: it turns a requested point count
  into a cash figure the till can actually take. By construction
  `pointsFor(amountFor(p)) ≥ p` — it may be *more* than requested
  (345 points in USD → $35 → 350 points), and the dialog shows that.
- **Amount mode accepts only multiples of `S(c)`** — cash the till can
  actually take. This replaces the dashboard's hard-coded "SYP is whole
  pounds" rule with the per-currency setting. With USD at its default
  step of 1, amount mode no longer accepts cents (it did before).
- **What is stored:** `payment_amount` = the cash actually taken;
  `points` = `pointsFor(payment_amount)`. Every top-up satisfies this,
  whichever field the operator typed in.

**Server is the authority.** The dashboard sends the `points` it displayed
alongside `amount`. The server recomputes `pointsFor(amount)` at its current
rates and rejects with 422 if they differ (typically: an exchange rate
changed after the dialog opened). The operator is therefore never told one
number while the wallet receives another. The dashboard's arithmetic is a
mirror for instant display, verified on every write — that is the condition
under which Invariant 4's "no conversion in TypeScript" is relaxed here.

**No floats on either side.** PHP uses bcmath on exact operands. TypeScript
uses BigInt fixed-point; a float version would disagree at boundaries
(`0.3 / 0.1 === 2.9999999999999996` → 2 points instead of 3) and every
such case would surface as a spurious 422.

## 4. API changes (ADDCore)

### `GET admin/reception/wallet-balance` — additive

Adds a `top_up` block: everything the dialog needs to convert locally.

```json
"top_up": {
  "point_rate_usd": "0.10",
  "min_points": 10,
  "currencies": [
    { "code": "USD", "rate_to_base": "1", "cash_step": "1.00", "decimal_places": 2 },
    { "code": "SYP", "rate_to_base": "0.0000680272", "cash_step": "100.00", "decimal_places": 2 }
  ]
}
```

Active currencies only; a non-base currency with no current exchange rate is
left out (it cannot be quoted, and `store` already refuses it). Existing keys
are untouched, so `PlanPurchasesPage` and `services/reception.ts` are
unaffected.

### `POST admin/reception/wallet-top-ups` — `points` becomes required

| Field | Rule |
|---|---|
| `points` | **new** — required, integer, `≥ wallet.top_up_min_points` |
| `amount` | required, numeric, `> 0`, **`decimal:0,2`** (new — matches `payment_amount decimal(14,2)`, so the verified amount is the stored amount) |
| others | unchanged |

After field rules pass, `points` must equal `pointsFor(amount, currency)`,
else 422 on `points` with new key `api.wallet.quote_stale`. The existing
`amount_below_one_point` check is subsumed by the minimum (`min_points ≥ 1`)
and its key is removed. Response shape unchanged; `points_credited` equals
the submitted `points`.

### `POST admin/reception/wallet-top-ups/preview` — removed

The dashboard was its only consumer. Route, controller method and tests go.

### Conversion code

- `CurrencyConversionService::toBaseExact(string $amount, string $from): ?string`
  — `bcmul(amount, rate_to_base, 12)`; exact for a 2-dp amount × 10-dp rate.
  FX stays in this service.
- `PointConversionService::currencyToPoints(string $amount, string $currency): ?int`
  — `usdToPoints(toBaseExact(...))`. `usdToPoints` already floors exactly via
  `bcdiv(..., 0)`; the bug was only the 2-dp rounding before it.
- `paymentInBaseCurrency()` and the float `convert()` call in
  `StoreWalletTopUpRequest` are deleted. `exchange_rate_to_base` and
  `point_rate_usd` audit fields are recorded exactly as today.

### Setting and currency column

- `SettingSeeder`: `setDefault('wallet.top_up_min_points', 10, Int)`.
  `UpdateSettingRequest` validates that key as `integer|min:1`. Read with a
  default of 10, so tests and fresh installs work before seeding.
- Migration: `currencies.cash_step decimal(14,2) default 1.00` for every
  row. `Currency` fillable/cast plus a model-level default (so a freshly
  created currency serializes `"1.00"`, not null),
  `StoreCurrencyRequest`/`UpdateCurrencyRequest`
  (`sometimes|numeric|gt:0|decimal:0,2`), `AdminCurrencyResource`.
  `CurrencySeeder` is deliberately **not** touched: it is an idempotent
  `updateOrCreate`, and listing `cash_step` there would reset an
  admin-edited step on every re-seed.

### Lang (`lang/{en,ar}/api.php`, `wallet` group)

- `min_points` — "A top-up must be at least :min points."
- `quote_stale` — "Rates changed since this top-up was prepared. Review the new figures and submit again."

## 5. Dashboard changes (ADDOS)

- **`modules/payments/utils/top-up-math.ts`** (new): pure BigInt functions
  `pointsForAmount`, `amountForPoints`, `remainderBelowOnePoint`, parsing the
  API's decimal strings exactly. No floats.
- **`modules/payments/composables/useTopUpQuote.ts`** (new): mode, the two
  linked inputs, currency, the `top_up` config, derived figures, min-points
  validation. Used by both forms so the logic exists once.
- **`WalletTopUpDialog.vue` and `WalletTopUpsPage.vue`**: replace the
  preview call with the composable.
  - Points mode (default): type points → shows "Collect 499,900 SYP →
    340 points"; if rounding raised the count, the higher count is what is
    shown and submitted.
  - Amount mode: type cash → shows "340 points" plus "199.92 SYP is not
    enough for another point".
  - Quick presets in points (10, 50, 100, 500), hiding any below the minimum.
  - Below minimum: inline error, submit disabled.
  - 422 `quote_stale`: show the message, re-fetch the balance (which carries
    fresh rates) so the figures update; **never resubmit automatically** —
    the operator confirms the new numbers with the member first.
- **`services/wallet-top-ups.ts`, `types/wallet-top-up.ts`**: drop
  `previewWalletTopUp` and its types; add `points` to the payload and the
  `top_up` block to `WalletBalanceResponse`.
- **Currencies page** (`currencies.config.ts`, `types/currency.ts`): a
  `cash_step` form field and column.
- **`usePoints.ts`**: its header comment says the dashboard holds no
  conversion arithmetic; rewrite it to point at `top-up-math.ts` and the
  server-verification condition above.
- `ar.json` / `en.json` keys for the new labels.

ADDOS has uncommitted changes from unrelated work (including
`src/stores/currency.ts`); this work goes on its own branch and leaves them
alone.

## 6. Tests

One table of vectors (`R = 0.10`, `F(SYP) = 0.0000680272`), asserted in
**both** PHPUnit and Vitest so the two implementations are proven to agree:

| Case | Input | Expected |
|---|---|---|
| floor, SYP | 500,000 SYP | 340 points |
| over-credit bug | 501,200 SYP | **340** (old code: 341) |
| float boundary | 0.30 USD | 3 points |
| points → SYP, step 100 | 340 points | 499,900 SYP → 340 points |
| minimum, SYP | 10 points | 14,800 SYP → 10 points |
| points → USD, step 1 | 345 points | $35 → 350 points |
| remainder | 500,000 SYP | 199.92 SYP below one point |
| new pound (`F = 0.0068027211`) | 5,000 SYP | 340 points |
| new pound, step 1 | 340 points | 4,998 SYP → 340 points |
| new pound, step 10 | 340 points | 5,000 SYP → 340 points |
| new pound, minimum | 10 points | 147 SYP → 10 points |

`amountFor` and the remainder are dashboard-only, so those rows run in Vitest
only.

Backend feature tests (`WalletTopUpControllerTest`):
- `points` below the minimum → 422; the minimum follows the setting.
- `points` ≠ `pointsFor(amount)` → 422 `quote_stale`, nothing written.
- An exchange-rate change between the balance read and the submit that
  moves `pointsFor(amount)` → 422 (a change too small to move the floor
  still succeeds, correctly).
- `amount` with 3 decimals → 422.
- `wallet-balance` returns `top_up`, excluding a currency with no rate.
- Currency `cash_step` create/update/validation; settings validation for the
  new key.
- The two `preview` tests are deleted with the endpoint; the
  `amount_below_one_point` test becomes the minimum-points test.

Dashboard: `top-up-math.spec.ts` (vectors above), composable spec, and
updates to the existing `WalletTopUpDialog`, `WalletTopUpsPage`,
`wallet-top-ups` service and `CurrenciesPage` specs.

## 7. Deploy order

Backend first: migrate, then run `SettingSeeder` (idempotent `setDefault`)
so the setting row exists for the settings page to edit. Deploy the
dashboard after — an old dashboard that sends no `points` gets a 422 rather
than a wrong credit, so a gap between the two is safe but blocks top-ups;
keep it short.

## 8. Out of scope

- The base currency is assumed to be USD, as `point.rate_usd` already
  assumes. Unchanged here.
- No "change to return" bookkeeping: `payment_amount` is what the operator
  says was taken. Amount mode only *shows* the sub-point remainder.
- No member-facing top-up, no bonus rules (unchanged from Spec C).
