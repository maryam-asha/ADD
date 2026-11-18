# Multi-currency support: admin-managed `currencies`, not a hardcoded enum

**Status:** resolved 2026-08-20. **Owner:** Maryam Asha.

## What changed

The Unit-1 spec ([money-model.md](money-model.md)) scoped `exchange_rates`
to exactly one pair — USD/SYP — and treated "any currency beyond that" as
out of scope. That line is now **superseded**: the business needs to admin-
manage additional currencies (e.g. EUR later) from the dashboard with no
code deploy, so a real `currencies` table replaces the old hardcoded
two-case `App\Domain\Finance\Enums\Currency` enum.

## Decision

- `currencies` (`code` string(3) primary key, `name` `{ar,en}` JSON,
  `symbol`, `decimal_places`, `is_base`, `is_active`, `order`) is now the
  source of truth for every currency the system knows about, admin-managed
  through `Api\V1\Admin\CurrencyController`. A currency is never hard-
  deleted — every price-bearing/preference column FKs to `currencies.code`
  with `restrictOnDelete()` — "removing" one means flipping `is_active` to
  `false` via a dedicated status endpoint.
- The base currency is **fixed via `is_base`**, not user-configurable and
  never multi-base: exactly one row carries `is_base = true` (seeded as
  USD), and that row's status can never be flipped — every conversion and
  every resolved fallback (`CurrencyResolver`) assumes exactly one always-
  active base row exists. This is the one invariant the app layer enforces
  itself (no DB constraint), same as before this change.
- `exchange_rates` keeps its original purpose — the sole conversion table —
  but is generalized: every row now names the currency it converts
  (`currency_code`) and stores `rate_to_base` ("units of the base currency
  per 1 unit of `currency_code`"). The base currency itself never gets a
  row — its rate to itself is definitionally 1 — enforced both at the
  validation layer (`StoreExchangeRateRequest` excludes `is_base` rows) and
  left implicit in `CurrencyConversionService`.
- `CurrencyConversionService::convert()` now routes any pair through the
  base currency instead of assuming USD/SYP are the only two currencies
  that exist: converting between two non-base currencies (e.g. USD → EUR)
  converts amount → base using the `from` currency's rate, then
  base → target using the `to` currency's rate, rounding only the final
  result. The USD/SYP behavior this replaces is unchanged bit-for-bit — it
  was always just the `toCurrency === base` / `fromCurrency === base`
  special cases of this general rule.
- Validation (`preferred_currency`, `pricing_currency`, `currency_code`,
  ...) switches from `Rule::enum(Currency::class)` to
  `Rule::exists('currencies', 'code')->where('is_active', true)` — a new
  currency becomes valid input the moment an admin creates and activates it,
  with no code change.

## Guard

[`ExactlyOneBaseCurrencyTest`](../../tests/Guards/ExactlyOneBaseCurrencyTest.php)
asserts exactly one `currencies` row has `is_base = true`, and that it's
active, against the migrated schema state.

## Addendum (2026-08-31): base currency becomes reassignable

Superseded clause: "not user-configurable and never multi-base... that
row's status can never be flipped." Reversed deliberately, pre-launch,
while no real exchange-rate history exists to invalidate — reassignment
is expected once branch expansion needs a different natural base.

- `is_base` becomes settable through one dedicated, guarded action
  (never a plain field on the generic create/update requests — same
  separation-of-concerns precedent as `is_active`/status).
- The single-base invariant (`ExactlyOneBaseCurrencyTest`) still holds
  at all times — this only changes *which* row it's true for, never
  "exactly one."
- Reassignment is blocked outright while any `exchange_rates` row
  exists: every stored `rate_to_base` is meaningless once the base it
  was computed against changes, and this decision does not attempt to
  recompute financial history automatically. A future reassignment at
  real-expansion time requires clearing/archiving `exchange_rates`
  first, as its own deliberate, separate step — not something this
  endpoint does silently.
