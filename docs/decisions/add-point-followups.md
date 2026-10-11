# ADD Point — follow-up work and known gaps

**Status:** recorded 2026-09-14, on completion of the ADD Point system.
**Spec:** [2026-09-12-add-point-system-design.md](../superpowers/specs/2026-09-12-add-point-system-design.md)
**Plan:** [2026-09-12-add-point-system.md](../superpowers/plans/2026-09-12-add-point-system.md)

Points are now the unit of account: every price and wallet balance is a
whole integer of points, and currency is derived for display and for
recording cash actually taken. This file records what was deliberately
left undone, so none of it decays into folklore.

Nothing here blocked the merge. Each item names why it was deferred and
what it costs to leave alone.

## 1. Events are priced in points — ✅ DONE 2026-09-14

Closed. `events.price` and `events.currency` were dropped; `price_points`
is the only stored price, and `EventResource` / `EventBrowseService`
derive `price_usd` / `price_display` / `currency` per request through
`PointConversionService`, the same shape `PlanResource` uses.

⚠️ **The urgency was higher than this document originally recorded.** By
the time the conversion ran, events had stopped being display-only:
`EventRegistrationService` debits the member wallet via `debitGeneral()`
on registration. `price_points` had been added alongside `price` and
`currency` without removing them, so an event carried **two unlinked
prices** — the registration charged `price_points` while every API
response displayed `price`. Nothing kept them in step, so an event could
show "$5.00" and charge 200 points (\$20.00).

The dashboard followed: the events table and form now read and write
`price_points`, and the currency selector is gone.

Every pricing table in the product now stores points:
`events`, `plans`, `spaces`, `plan_balances`, `wallet_transactions`.

## 2. `included_hours` is credited 1:1 as points

`MembershipController` credits `$plan->included_hours` — a
`decimal(8,2)` **hours** column — straight into the point ledger, so a
plan granting 8 hours grants 8 points ($0.80). The only remaining
`TODO(spec-C)` marker sits on it.

**This pre-dates the point system.** The same value was already credited
1:1 into the currency ledger; the integer column merely made it visible.
Converting it is an economics decision — what is an included hour
worth? — not a units bug, so it belongs to whoever owns plan pricing.

⚠️ One behaviour change rides along: `(int)` truncation means a plan with
`7.50` hours now grants 7 points, and `0.50` hours grants **0** — a
membership purchase that silently grants nothing. No such plan exists
(all six seeded values are whole) and `StorePlanRequest` still accepts
`numeric`, so this is reachable only by creating a fractional-hours plan.

## 3. `amount_owed` stays currency-denominated

`bookings.amount_owed` and `walkin_sessions.amount_owed` remain
`decimal(10,2)` USD alongside their `currency` columns.

Safe, and verified so by the final review: these columns are **written
and logged but never read back into a balance**. They record what was
owed at a moment in time, not a spendable balance. Converting them is a
separate schema change with USD assertions across five test files.

## 4. The FX leg still uses float — ✅ resolved for the credit path 2026-10-10

Resolved for the credit path on 2026-10-10: top-up credits — and the
request's check of the dashboard's quoted `points` — now go through
`ReceivedMoneyConverter::toPoints()` (bcmath, floored once on the exact
value; docs/decisions/unified-payments.md), see
[2026-10-10-wallet-top-up-points-entry-design.md](../superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md).
That spec's own `toBaseExact()`/`currencyToPoints()` pair was folded into
the converter when the two branches merged, so money becomes points in
one place.
The two float call sites this section recorded (a validator-side check and
a controller-side `paymentInBaseCurrency()`) are gone, so there is no
longer a second computation that could desync "will this be rejected" from
"what actually gets credited."

Display conversion is unchanged: `pointsToCurrency()` still goes through
the float `convert()`. It only renders a figure and never writes a balance.

## 5. `PointRateIsNeverFloatTest` has a blind spot

The guard matches the literal `->rate()` adjacent to a cast or operator.
It therefore catches `(float) $points->rate() * 2` but **not**:

```php
$r = $points->rate();
// ... later, undetected:
$x = (float) $r * 2;
```

That variable idiom already exists in `WalletTopUpController`
(`$pointRate = $points->rate();`), so it is the most natural way a future
contributor would introduce exactly the bug the guard exists to prevent.

**Why it shipped anyway:** the invariant holds in fact today — both
`rate()` callers outside the service only record the value, never compute
on it — and the guard is a strict improvement over the nothing that
preceded it. Closing the gap properly needs scope-aware static analysis
rather than a regex, which is its own piece of work.

## 6. `autoClose()` can bill a negative charge

`SessionClosureService::autoClose()` has no guard against a session
checked in **after** closing time. Carbon 3's `diffInSeconds` is signed,
so `CloseOverdueReceptionSessions` passing today's closing time yields a
negative duration.

Pre-existing — verified identical before the point work. Partly mitigated
now: `AmountCalculator` gained a `$seconds <= 0 → 0` guard, so the charge
is 0 rather than negative. `closeOut()` guards this properly with
`checkout_before_checkin`; `autoClose()` should too.

## 7. Frontend: two display inconsistencies

- `usePoints()` uses raw `Intl.NumberFormat`, which
  `utils/format/index.ts` explicitly forbids (Arabic-Indic digit risk
  under `ar`). `plans.config.ts` uses `formatNumber` for the same job, so
  the two can disagree under Arabic.
- `MemberDetailPanel.vue:193` still leads with `general_balance` (cash)
  rather than points — the one balance display not converted.

## 8. Deprecated response keys, removable after one release

`PlanResource` still emits `converted_amount` / `converted_currency`,
restored deliberately because the member app may read them. They are
derived from the same computed values as `price_display` / `currency`, so
they cannot drift. Remove them once the member app is confirmed off them.

## Two things to confirm at deploy time

**1. The "all data is disposable" premise.** Both migrations drop and
recreate columns with **no backfill**, which is safe only because every
row was seeded test data (confirmed 2026-09-12). That premise is dated —
re-confirm it against production before running them, because they are
irreversible.

**2. Whether the member app reads the deprecated keys** (see §8), before
anyone removes them.
