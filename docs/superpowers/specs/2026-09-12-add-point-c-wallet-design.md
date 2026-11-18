# ADD Point — Spec C: Wallet ledger and top-up

**Status:** design approved 2026-09-12. **Owner:** Maryam Asha.
**Parent:** [2026-09-12-add-point-system-design.md](2026-09-12-add-point-system-design.md).
**Depends on:** Spec A (conversion service) and Spec B (point pricing).

⚠️ **This is the highest-risk spec in the project.** It changes the
meaning of every existing row in `wallet_transactions` — real member
balances. Read the Migration section twice.

## Goal

Make the ledger point-denominated, make top-up credit points while
recording the cash actually taken, and surface points in the dashboard.

## 1. Ledger schema

```
wallet_transactions
├─ amount                 decimal(10,2) → integer   ← now POINTS, signed
├─ payment_currency       keep  ┐
├─ payment_amount         keep  │  audit only — never feeds a balance
├─ exchange_rate_to_base  keep  │
└─ point_rate_usd         NEW   ┘  decimal(8,4)
```

`amount` stays **signed** — positive credit, negative debit — exactly as
today. Only the unit changes. `unsignedInteger` would be wrong here.

`point_rate_usd` is nullable: null for every pre-migration row (there
was no point rate then) and for any transaction where no cash moved.

### The four audit columns

Per Invariant 3, these record what happened and are **never read by a
balance computation**. A guard test enforces that.

They are what makes the answer to "هل هناك داعي لتخزين المبلغ بالليرة؟"
a firm yes: with SYP moving weekly, a receipt for 500,000 SYP cannot be
reproduced from a point count alone once the rate has moved. Storing the
amount *and* both rates freezes that moment permanently.

## 2. The migration — the dangerous part

```
for each wallet_transaction:
    points = floor( |amount| / point.rate_usd )   ← credits
    points = ceil ( |amount| / point.rate_usd )   ← debits
    restore original sign
```

**Why floor for credits but ceil for debits, even here:** applying one
rule to both would shift net balances. Rounding each row in the
direction it was originally applied keeps every member's balance within
one point of its true value, and never in the member's favour on a
credit or against them on a debit.

### Required safeguards

1. **Dry-run first.** An Artisan command `points:migrate-ledger --dry-run`
   reports, without writing: total rows, rows losing a fraction, and
   every wallet whose *net balance* changes, largest first.
2. **Log every adjusted row** — id, old amount, new points, delta.
3. **Balance snapshot.** Before and after, compute every wallet's
   balance (via the existing `WalletService` read path) and assert no
   wallet moved by more than one point. Fail the migration if one does —
   that means a bug, not rounding.
4. **Run inside a transaction** so a mid-way failure leaves nothing
   half-converted.
5. **Back up `wallet_transactions` before deploying.** State this in the
   deploy checklist; `down()` is lossy for the same reason spec B's is.

### Ordering constraint

This migration must run **after** spec B is deployed, because
`point.rate_usd` must already be the rate that spec B's prices were
computed against. Running them in the same deploy window is acceptable;
running C before B is not.

## 3. `WalletService` changes

The debit-resolution algorithm — signature grouping, soonest-expiry
ordering, the all-or-nothing category fallback — is **unchanged**. Only
the unit changes.

Mechanical edits:

- `bcadd`/`bcsub`/`bccomp` scale drops from `2` to `0` throughout.
- `'0.00'` literals become `'0'`.
- Method signatures take `int $points` instead of `string $amount`.
- `sumAmounts()` returns an int.

⚠️ **Do not "simplify" bcmath to native integer arithmetic here.**
PHP integers would work for the arithmetic, but the surrounding code
(and the guards) assume bcmath strings; a mixed regime is where sign and
comparison bugs hide. Keep bcmath at scale 0 and cast once at the
boundary.

The `InsufficientBalanceException` message changes from currency to
points wording.

## 4. Top-up flow

Decided 2026-09-12: **receptionist enters cash received, system credits
points** — matching what physically happens at the desk.

```
Input:   500,000 SYP  ·  method: cash
   │
   ├─ SYP → USD   via CurrencyConversionService   →  $34.01
   ├─ USD → points via usdToPoints() [floor]      →  340.1 → 340
   │
Credit:  340 points
Record:  payment_amount=500000, payment_currency=SYP,
         exchange_rate_to_base=0.0000680272, point_rate_usd=0.10
```

### Request changes

`StoreWalletTopUpRequest` keeps `amount` + `currency` (the cash taken) —
**no new field**, since the receptionist still enters cash. Add a
validation guard: reject when the conversion would credit **zero
points**, which happens for a tiny payment and would otherwise silently
take money for nothing.

### Response changes

Add to the existing receipt payload:

```json
{
  "points_credited":      340,
  "previous_balance_points": 1200,
  "new_balance_points":      1540,
  "point_rate_usd":       "0.10"
}
```

Keep `payment_amount`/`payment_currency`/`exchange_rate` as they are —
the receipt must show both what was paid and what was credited.

### Two existing defects to fix while here

Both were found during the 2026-09-12 wallet review and are in the code
this spec rewrites anyway:

**(a) The top-up is not atomic.** `WalletTopUpController::store()`
creates the transaction via `creditGeneral()`, then applies the audit
fields in a second `forceFill()->save()` with no wrapping transaction.
If the second write fails, a credited balance exists with no payment
method, no operator, and no rates — money with no audit trail. **Wrap
both in `DB::transaction()`.**

**(b) `calculateGeneralBalance()` is a private duplicate** of
`WalletService`'s balance logic. With the unit changing, two copies is
now actively dangerous. **Delete it and call `WalletService`.**

## 5. Charge path cleanup

Remove the temporary points → USD conversion that spec B added at the
five booking call sites (grep `TODO(spec-C)`). `AmountCalculator`
already returns points; `WalletService` now takes points; the conversion
in between disappears.

Also resolve `plans.overage_rate`, deferred from spec B: convert it to
`overage_rate_points` if it is to be charged, or drop the column. **Do
not leave it as a currency decimal** — it would be the only one left,
and a trap for the next reader.

## 6. Dashboard (ADDOS)

Files: `src/add-os/modules/payments/` and `modules/members/`.

- **`WalletTopUpDialog.vue`** — as the receptionist types a cash amount,
  show a live preview: "≈ 340 points". Preview is computed by the
  **backend**, via a small `POST admin/reception/wallet-top-ups/preview`
  endpoint, not in the frontend. Duplicating the floor/rate logic in
  TypeScript would violate Invariant 4 and drift the moment the rate
  changes.
  *Superseded 2026-10-10:* the dashboard now converts locally and the
  server verifies every top-up instead; the preview endpoint is gone. See
  [2026-10-10-wallet-top-up-points-entry-design.md](2026-10-10-wallet-top-up-points-entry-design.md).
- **`WalletTopUpsPage.vue`** — add a points column; keep the cash
  columns.
- **Balance displays** — show points as the primary figure with the
  currency equivalent secondary: `340 نقطة (≈ 24,990 ل.س)`.
- **Settings page** — surface `point.rate_usd` with a clear warning that
  changing it reprices everything.
- **i18n** — add `point`/`points` keys to both `ar` and `en`.

## 7. Tests

**Migration** — a 25.00 credit at rate 0.10 becomes 250 points; a
debit of 7.40 becomes 74; net balance per wallet moves by ≤ 1 point
across a seeded fixture; dry-run writes nothing.

**Top-up** — 500,000 SYP credits 340 points and records all four audit
fields; a payment too small to buy one point is rejected 422; the whole
action is atomic (simulate failure on the audit write and assert no
credit survives).

**Wallet** — the full existing `WalletService` suite, re-expressed in
points: signature grouping, expiry, the all-or-nothing category
fallback, and concurrent-debit locking must all still pass. **These are
the regression tests that prove the algorithm survived the unit
change.**

**Revaluation** — a wallet with 340 points reports ≈ $34 at rate 0.10
and ≈ $40.80 at 0.12, with no ledger row modified (Invariant 3).

**Guard** — new `WalletBalanceIgnoresAuditColumnsTest`: scans
`WalletService` for any read of `payment_amount`, `payment_currency`,
`exchange_rate_to_base`, or `point_rate_usd`. Enforces Invariant 3.

## Files touched

| File | Change |
|---|---|
| `database/migrations/*_convert_wallet_ledger_to_points.php` | New |
| `app/Console/Commands/MigrateLedgerToPoints.php` | New (dry-run) |
| `app/Domain/Membership/Services/WalletService.php` | Scale 2 → 0, int signatures |
| `app/Domain/Membership/Models/WalletTransaction.php` | Casts |
| `.../Reception/WalletTopUpController.php` | Points, atomicity, dedupe, preview |
| `app/Http/Requests/Admin/Reception/StoreWalletTopUpRequest.php` | Zero-point guard |
| 5 booking services | Remove spec-B scaffolding |
| `app/Domain/Booking/Services/AmountCalculator.php` | Finalize |
| `tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php` | New |
| ADDOS `payments/`, `members/`, `lang/` | UI + i18n |

## Done when

Full suite green including every pre-existing wallet test, Pint clean,
dry-run reviewed on a production-shaped dataset, backup taken, and a
manual reception top-up produces a receipt showing both the cash taken
and the points credited.

## Explicitly not done here

- **No member-facing point purchase.** Top-up stays a reception action.
- **No point transfer between members.**
- **No change to categories, expiry, or restriction.** Those mechanisms
  are unit-agnostic and stay exactly as they are.
- **No promotional/bonus point rules** (e.g. "top up 1000, get 50
  free"). The `source` enum could express it later; out of scope now.
