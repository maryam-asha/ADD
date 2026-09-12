# ADD Point — Spec B: Pricing in points

**Status:** design approved 2026-09-12. **Owner:** Maryam Asha.
**Parent:** [2026-09-12-add-point-system-design.md](2026-09-12-add-point-system-design.md).
**Depends on:** Spec A (`PointConversionService` must be in production).

**Behaviour change: read-side only.** Prices become point counts and the
API reports them. The wallet ledger is untouched — that is spec C.

## Goal

Move the three pricing tables to whole-point columns, and extend the
existing display-conversion response shape to carry points.

## 1. Schema

Three tables, one migration each, all in the flat
`database/migrations/` directory per the repo convention.

| Table | Remove | Add |
|---|---|---|
| `plans` | `price` decimal(10,2), `pricing_currency` FK | `price_points` unsignedInteger |
| `spaces` | `hourly_rate` decimal(10,2), `pricing_currency` FK | `hourly_rate_points` unsignedInteger nullable |
| `plan_balances` | `amount` decimal(10,2) | `amount_points` unsignedInteger |

`spaces.hourly_rate_points` stays **nullable** — a space need not have a
price, which the current schema already allows and the existing FK
comment calls out explicitly.

`unsignedInteger` is correct per Invariant 5: the `MoneyIsDecimalOnly`
guard forbids `->float(`/`->double(` only, and a point count is a
cardinal quantity, not a monetary decimal.

### Migration shape (each table)

Three steps in one `up()`, so no window exists where a row has neither
column populated:

1. `add` the new `*_points` column, nullable.
2. **Backfill** — for each row, `usdToPoints(price converted to USD)`.
   Rows already priced in USD convert directly; rows in another currency
   route through `CurrencyConversionService` first.
3. `change()` the column to non-nullable (except `spaces`), then drop
   the old columns and their FKs.

⚠️ **Dropping a foreign key before its column.** `pricing_currency` on
both `plans` and `spaces` carries an FK to `currencies.code` added in
`2026_08_20_1003xx`/`1004xx`. `dropForeign` must run before
`dropColumn`, or MySQL errors. SQLite (used by the test suite) tolerates
either order, so **this fault would pass tests and fail on deploy** —
write it correctly the first time.

### Backfill rounding

The backfill is a **credit-direction** conversion (it preserves existing
value), so it uses `usdToPoints` → floor, per Invariant 2. A $49.97 plan
at rate 0.10 becomes 499 points, not 500.

**This changes live prices by up to one point (≤ $0.10).** Accepted:
the alternative is fractional prices, which Invariant 2 forbids. The
migration logs every row whose backfilled value lost a fraction, so the
team can review and round-number the affected prices afterwards.

### Reversibility

`down()` restores the decimal columns and backfills
`pointsToUsd(price_points)` with `pricing_currency = 'USD'`. **This does
not round-trip exactly** — the floor in `up()` is lossy, and prices
edited in points after migration have no original USD value to restore.
Documented in the parent spec as accepted risk; rollback is
disaster-recovery, not routine.

## 2. Validation

Every endpoint accepting a price now accepts a point count. In
`StorePlanRequest`, `UpdatePlanRequest`, `StoreSpaceRequest`,
`UpdateSpaceRequest`, and the plan-balance requests:

```php
'price_points' => ['required', 'integer', 'min:0'],
```

`integer` is what enforces Invariant 2's consequence — a 12.5-point
price is rejected at the boundary rather than failing later at charge
time. `min:0` allows a free plan; `min:1` would forbid one.

Remove `pricing_currency` from these requests entirely. It is no longer
a client-supplied concept.

## 3. API response shape

Extends the pattern `PlanResource` already established
([currency-header-design](2026-08-11-currency-header-design.md)) rather
than inventing a new one. Every priced resource emits:

```json
{
  "price_points":  500,
  "price_usd":     "50.00",
  "price_display": "735000.00",
  "currency":      "SYP"
}
```

| Field | Meaning | Source |
|---|---|---|
| `price_points` | **The authoritative price.** | Column |
| `price_usd` | Points at today's rate | `pointsToUsd()` |
| `price_display` | Points in the resolved currency | `pointsToCurrency()` |
| `currency` | Which currency `price_display` is in | `CurrencyResolver` |

`price_usd`, `price_display`, and `currency` are **already emitted
today** by `PlanResource` with exactly these names and semantics. The
dashboard's existing display code therefore keeps working unchanged;
`price_points` is purely additive. This is the main reason for reusing
the shape rather than designing a cleaner one.

`converted_amount`/`converted_currency` (the older pair `PlanResource`
also emits) are kept for one release and marked deprecated in the
resource's docblock, since the member app may still read them.

### Currency resolution is unchanged

`CurrencyResolver` keeps resolving `currency` header → `preferred_currency`
→ base. Its output still only ever decides *display*, so
`PreferredCurrencyIsDisplayOnlyTest` keeps passing — and now passes more
robustly, because after this spec there is no currency column on a
pricing table for it to accidentally influence.

### Resources touched

`PlanResource`, `SpaceResource`, and the `balances` block inside
`PlanResource` (which currently emits `amount` as a float — it becomes
`amount_points` as an integer, plus the same derived trio).

⚠️ `PlanResource` currently casts balance amounts with `(float) $b->amount`.
Float on a monetary value violates decision #15's spirit even though the
guard only scans migrations. Fix it to an integer cast while here.

## 4. Booking charge path

`AmountCalculator::forRange()` currently returns
`[bcmul($hours, $rate, 2), $space->pricing_currency]`.

It becomes:

```php
/** @return int  whole points, rounded up */
public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): int
```

- Returns a bare `int` — the currency half of the tuple is gone, since
  points carry no currency.
- Rounds **up** (Invariant 2, debit direction): 37 minutes at 12
  points/hour is 7.4 → 8 points.
- Computes with bcmath at scale 4, then ceils once at the end — never
  per-step.

**Callers to update — all five:** `BookingCreationService`,
`SessionClosureService`, `BookingCancellationService`,
`BookingApprovalService`, and `BookingExtensionService`. Each currently
destructures `[$amount] = ...` and passes a decimal string to
`WalletService`. In this spec they keep passing to the *unchanged*
wallet (still currency-denominated), so each must convert
points → USD string at the call site via `pointsToUsd()`.

⚠️ That conversion is **temporary scaffolding for one release only.**
Spec C removes it when the ledger itself becomes point-denominated.
Mark each call site with `// TODO(spec-C): drop conversion once ledger
stores points` so they are trivially greppable. This is the one place
where A/B/C decomposition costs real work — the alternative was a single
much riskier change.

## 5. Tests

**Feature** — plan and space CRUD accept `price_points` and reject
`12.5`; index/show emit all four fields; the `currency` header changes
`price_display` but never `price_points`; a plan priced at 500 points
displays `$50.00` at rate 0.10 and `$60.00` at 0.12 without the stored
value changing (Invariant 3).

**Migration** — a plan at `50.00 USD` backfills to `500`; a plan at
`49.97 USD` backfills to `499` and is logged; a space with a null
`hourly_rate` backfills to null, not 0.

**Booking** — 90 minutes at 12 points/hour charges exactly 18; 37
minutes charges 8, not 7.

**Guard** — extend `PointConversionIsCentralizedTest` (spec A) to cover
the new resources: none of them may compute a rate inline.

## Files touched

| File | Change |
|---|---|
| `database/migrations/*_convert_plans_to_points.php` | New |
| `database/migrations/*_convert_spaces_to_points.php` | New |
| `database/migrations/*_convert_plan_balances_to_points.php` | New |
| `app/Domain/Membership/Models/Plan.php`, `PlanBalance.php` | Fillable + casts |
| `app/Domain/Foundation/Models/Space.php` | Fillable + casts |
| `app/Http/Requests/Admin/Store|UpdatePlanRequest.php` | Rules |
| `app/Http/Requests/Admin/Store|UpdateSpaceRequest.php` | Rules |
| `app/Http/Resources/PlanResource.php`, `SpaceResource.php` | Shape |
| `app/Domain/Booking/Services/AmountCalculator.php` | Return int points |
| 5 booking services | Call-site conversion + TODO |
| `database/factories/PlanFactory.php`, `SpaceFactory.php` | Point values |

## Done when

Full suite green, Pint clean, and a manual check that the dashboard's
plans page still renders prices (it reads `price_display`/`currency`,
which are unchanged).

## Explicitly not done here

- **The wallet ledger is untouched.** Balances are still currency
  amounts; spec C migrates them.
- No dashboard UI work. The dashboard will show converted currency as it
  does today; surfacing point counts in the admin UI is spec C.
- `overage_rate` on plans is **not** converted in this spec — it is not
  currently charged anywhere. Flagged for spec C to handle or delete.
