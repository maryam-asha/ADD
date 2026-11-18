# ADD Point system — umbrella design

**Status:** design approved 2026-09-12, ahead of implementation.
**Owner:** Maryam Asha. **Type:** architectural design, decomposed into
three sequential specs (A, B, C) that each ship independently.

**Do not implement from this document.** It fixes the cross-cutting
rules and the decomposition. Each lettered spec below is the buildable
unit; this file is what they all must agree with.

## The decision in one line

**ADD Point becomes the unit of account for the entire product.** Every
price, every wallet balance, and every charge is a whole number of
points. Currency — USD, SYP, or any other — exists only to convert
points into something a human recognizes at the moment of display or at
the moment cash changes hands.

## Why points at all

Today a price is a `decimal` plus a `pricing_currency` string, and the
Syrian pound moves fast enough that a price set in SYP is wrong within
weeks. Pricing in points decouples the product's internal economy from
FX movement: `plans.price_points = 500` stays meaningful regardless of
what the pound does, and a single admin-controlled lever
(`point.rate_usd`) reprices everything at once when that is genuinely
intended.

## The two conversions, deliberately separate

| Step | Conversion | Source of truth | Changes |
|---|---|---|---|
| 1 | points ↔ USD | `point.rate_usd` setting | Rarely, by admin decision |
| 2 | USD ↔ SYP/EUR/… | `exchange_rates.rate_to_base` | Often, external service + admin override |

Collapsing these into one "points → SYP" rate would tie the point
economy to daily FX noise. Step 1 is a business lever; step 2 is market
data. They are never combined into a stored number.

```
        point_rate_usd                 rate_to_base
   points ─────────────► USD ──────────────────────► SYP / EUR / …
   (stored)            (base)                      (display only)
```

### Direction convention (do not get this backwards)

`exchange_rates.rate_to_base` means **units of base per 1 unit of
`currency_code`** — SYP is ≈ `0.0000680272` because 1 SYP ≈ $0.000068.
Therefore SYP → USD **multiplies** by the rate and USD → SYP
**divides**. `CurrencyConversionService` already implements both
directions correctly and is reused as-is; no new FX math is written
anywhere in A, B, or C.

## Invariant 1 — points are stored, currency is derived

The database stores points. No currency amount is ever persisted *as a
balance or a price*. Every currency figure in an API response is
computed at read time.

This extends the existing wallet principle ("no stored balance",
[phase-3-membership-plan-wallet-mechanics.md](../../decisions/phase-3-membership-plan-wallet-mechanics.md))
one level up, and it is what keeps
[PreferredCurrencyIsDisplayOnlyTest](../../../tests/Guards/PreferredCurrencyIsDisplayOnlyTest.php)
passing: `preferred_currency` cannot influence pricing or wallet logic
if currency never enters storage or spend resolution at all.

**The single exception is audit fields** — see Invariant 3.

## Invariant 2 — points are whole numbers, and rounding favours the house

Points are integers. There is no such thing as 0.5 points: not in a
balance, not in a price, not in a charge.

Two rounding rules, each applied **exactly once**, at the ledger
boundary — never mid-calculation, where errors compound:

| Direction | Rule | Rationale |
|---|---|---|
| **Credit** (top-up, grant, refund) | `floor()` | Never credit more value than was actually paid in |
| **Debit** (booking, café, printing) | `ceil()` | Never charge less than what is owed |

```
Top-up:  500,000 SYP × 0.0000680272 = $34.01 ÷ 0.10 = 340.1 → floor → 340 pts
Charge:  37 min × 12 pts/hr          = 7.4 pts                → ceil  → 8 pts
```

**Consequence that must be enforced, not assumed:** because balances are
integers, every *price* must also be a whole number of points. A
12.5-point price would be unpayable. Spec B adds validation on every
`*_points` input rather than letting this fail silently at charge time.

## Invariant 3 — audit fields record history, and never feed a balance

Points revalue when `point.rate_usd` changes (decided 2026-09-12): a
member holding 100 points is worth $10 today and $12 if the rate moves
to $0.12. The stored `100` never changes.

That makes it *more* important, not less, to record what actually
happened at the moment money moved. `wallet_transactions` keeps
`payment_currency`, `payment_amount`, `exchange_rate_to_base` and gains
`point_rate_usd`.

These four columns answer "what did this member hand over, in what
currency, at what rates, on what day?" — needed to reconcile a cash
drawer, reprint a receipt, or defend a disputed top-up. **No balance
computation may ever read them.** A guard test enforces this.

> This settles the open question from the brief ("لا أعلم إذا هناك داعي
> لتخزين المبلغ في حال الدفع بالعملة السورية"): **yes — store it.**
> Without the SYP amount and the rate used, a receipt cannot be
> reproduced once the rate moves, which for SYP is days.

## Invariant 4 — one conversion service, no ad-hoc arithmetic

All point↔USD conversion goes through a single
`App\Domain\Finance\Services\PointConversionService` (spec A). No
controller, resource, or model performs `amount * rate` or `/ 0.10`
inline. A guard test scans for stray arithmetic on the rate setting.

This mirrors the existing rule that FX math lives only in
`CurrencyConversionService`.

## Invariant 5 — bcmath, never float

PRD decision #15 forbids FLOAT/DOUBLE for monetary values, enforced by
[MoneyIsDecimalOnlyTest](../../../tests/Guards/MoneyIsDecimalOnlyTest.php).
Point *counts* are `unsignedInteger` columns, which that guard permits
(it forbids `->float(`/`->double(` only). But the **rate** is a decimal
string and every conversion multiplies by it, so all conversion
arithmetic uses `bcmul`/`bcdiv`/`bccomp`, exactly as `WalletService` and
`AmountCalculator` already do.

`point.rate_usd` is stored as `SettingValueType::String`, not `Int`
(which would truncate `0.10` to `0`) and not a float. It is fed straight
into bcmath as a decimal string.

## Decomposition

Three sequential specs. Each is independently shippable, testable, and
reviewable; B and C both depend on A, and C depends on B only for the
shared resource shape.

| Spec | Scope | User-visible? |
|---|---|---|
| **A — Foundation** | `point.rate_usd` setting, `PointConversionService`, guard tests | No |
| **B — Pricing** | `plans`, `spaces`, `plan_balances` → points; API resource shape | Yes (read) |
| **C — Wallet & top-up** | Ledger migration, top-up flow, charge path, dashboard UI | Yes (write) |

Building these as one change would put an irreversible ledger migration
in the same review as a new service class. They are split so the risky
step lands last, on top of foundations already in production.

### Why this order

A introduces no behaviour change at all — it is pure addition, so it can
ship and bake. B changes what numbers the API *reports* but not what the
ledger *holds*. C migrates the ledger, which is the one genuinely
irreversible step, and by then the conversion layer it depends on has
been exercised in production by B.

## Risks carried by this design

**1. The ledger migration (spec C) is the highest-risk step in the
project.** `wallet_transactions.amount` changes meaning from "currency
amount" to "points". The backfill divides by the rate and floors, so any
balance that does not divide evenly changes by a fraction of a point.
Spec C specifies logging every adjusted row and a dry-run mode.

**2. Pricing migration (spec B) is not cleanly reversible.** `down()`
restores USD using the rate at migration time, but a price edited in
points after the fact will not round-trip to its original USD value.
Accepted: rollback is a disaster-recovery path, not a routine one.

**3. A rate change reprices the entire economy simultaneously.** This is
the intended behaviour (points revalue), but it means editing
`point.rate_usd` is a high-impact action. Spec A routes it through the
existing sensitive-action audit log and admin-only permission.

## Explicitly not done here

- **No per-point expiry or FIFO/LIFO lot tracking.** Points do not
  remember the rate they were bought at — that was considered and
  rejected on 2026-09-12 in favour of revaluation. The existing
  category/`expires_at` mechanism is untouched and still governs which
  points expire.
- **No change to wallet categorization, restriction, or debit
  resolution.** `WalletService`'s signature-grouping algorithm keeps
  working unchanged; only the *unit* of `amount` changes.
- **No new payment channels.** `PaymentMethod` (cash/sham/mtn/syriatel)
  is untouched.
- **No member-facing point purchase endpoint.** Top-up stays a reception
  action in spec C.

## Related decisions

- [wallet-points-categorization.md](../../decisions/wallet-points-categorization.md)
  — the "points" in that filename means *categorized balances*, not this
  unit of account. This spec is what finally makes the name literal.
- [phase-3-membership-plan-wallet-mechanics.md](../../decisions/phase-3-membership-plan-wallet-mechanics.md)
  — no stored balance; read-time expiry. Both preserved.
- [multi-currency-support.md](../../decisions/multi-currency-support.md)
  — admin-managed currencies, `rate_to_base`. Reused unchanged.
- [currency-header-conversion-scope.md](../../decisions/currency-header-conversion-scope.md)
  — the `currency` header and display-conversion pattern that spec B
  extends.
