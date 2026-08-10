# Money model: per-price-point currency, not a forced USD base

**Status:** resolved 2026-08-08. **Owner:** Maryam Asha.

## What each source said

- **PRD v0.7.1** decision #15 and §5.7: the US dollar is the base currency;
  every transaction stores an exchange-rate snapshot; DECIMAL exclusively.
- **Master ERD v2.0** §5 declares a newer "Money Model", stated to have been
  decided *after* v0.7.1: currency is set per price point
  (`plans.pricing_currency`, `spaces.pricing_currency`,
  `business_cafe_orders.pricing_currency`) as it is actually entered, not
  forced to USD centrally. `exchange_rates` remains the one conversion
  table. Each `transactions` row stores its **original currency in full**
  plus a **derived `usd_equivalent`**, computed from the amount and the
  exchange-rate snapshot at execution — explicitly **non-authoritative,
  reporting-only, and never usable to compute a refund.**
  ERD v2.0 contradicts itself here: `bookings.price_snapshot_usd` survives
  from the old model unchanged, alongside the new one.
- **Document 4** was written without ERD v1.0 and does not carry this
  newer model at all — it keeps a single `payments.currency` enum of
  `[USD|SYP]` with no per-price-point currency concept.

## Decision

**ERD v2.0's Money Model is adopted, per [structure-reference.md](structure-reference.md).**
Concretely:

- Every price-bearing table (`plans`, `spaces`, `business_cafe_orders`, and
  any future one) carries its own `pricing_currency`, set as entered — never
  forced to USD.
- `exchange_rates` is the sole conversion table, admin-managed.
- `transactions.amount` + `transactions.currency` is the authoritative
  record of what actually happened. `transactions.usd_equivalent` is
  derived from `amount × exchange_rate_snapshot` for reporting only, and
  **must never be read back to authorize or compute a refund** — the guard
  below only checks the schema shape; the "never for refunds" half of this
  rule is a code-review obligation on every future PR that touches refunds.
- `bookings.price_snapshot_usd` (and any other single-USD-base leftover) is
  **removed**, not kept alongside the new model as ERD v2.0's own contradiction
  does. There is no `bookings` table yet in this codebase (it lands in
  Phase 5) — so nothing to delete today; this is a constraint on that
  migration when it is written, not a retroactive edit.
- The wallet (`wallets`, `wallet_transactions`) is explicitly **not**
  affected — it keeps working in points, implicitly tied to USD, exactly as
  before. ERD v2.0 §5 draws this boundary itself: the new model governs
  *pricing* (`plans`, `spaces`, `business_cafe_orders`) and *transactions*,
  not the wallet's point balance.

## What this changed in code

Nothing yet — no pricing or transaction table exists in this codebase before
Phase 3/4. This record is the binding constraint those migrations must
follow when written.

## Guard

None yet (nothing to check before Phase 3/4 introduces the tables). Phase 4
adds: no transaction persists without an `exchange_rate_snapshot`; no
money column anywhere is FLOAT/DOUBLE (already guarded repo-wide by
[`MoneyIsDecimalOnlyTest`](../../tests/Guards/MoneyIsDecimalOnlyTest.php));
and a naming check that no new migration reintroduces a
`*_snapshot_usd`/single-USD-base column.

## Update — 2026-08-09

`exchange_rates` is now implemented: `app/Domain/Finance/Models/ExchangeRate.php`,
migration `2026_08_09_160001_create_exchange_rates_table.php`. It's used
for two purposes sharing the one table, per the original decision above:
live, unstored display conversion (`preferred_currency`, see
`docs/superpowers/plans/2026-08-09-display-currency.md`), and — not yet
built — `exchange_rate_snapshot` on actual financial transactions. This
is not a new decision, just infrastructure catching up to this one.
