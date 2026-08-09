# Wallet/points categorization — unified design for Phase 3

**Status:** design approved 2026-08-08, ahead of implementation.
**Owner:** Maryam Asha. **Type:** design doc, not a conflict resolution —
written so Phase 3's first migration is built to this shape directly,
instead of shipping a minimal `wallet_transactions` table that gets
reworked once categorized balances turn out to be needed.

**Do not build any of this before Phase 3.** No migration, model, or
logic described here exists yet. When Phase 3 starts, this is the spec
for `wallet_transactions` and its two new companions — build to this
shape from the first migration, not incrementally.

## The problem this unifies

A wallet can accumulate credit from more than one source, and not all
credit is fungible:

- a plain top-up (spendable on anything, rolls over — no, it doesn't;
  see below),
- a plan's included allowance (e.g. free room hours), restricted to a
  specific use,
- a gift or promotional credit, possibly restricted to a specific
  service or to specific people,
- an internal allocation a company admin grants to specific employees
  from the company's shared wallet, possibly restricted to a service
  (the office café, printing) or to a specific space.

Rather than model each of these as a different mechanism, one
`wallet_transactions` shape covers all of them via optional fields set
at creation time. A transaction with none of the optional fields set
behaves exactly like today's plain top-up.

## Schema

### `wallet_transactions` — new optional columns

| Column | Type | Notes |
|---|---|---|
| `category` | `string` + PHP backed enum, default `general` | Initial values: `general`, `cafe`, `printing_internet`, `space_specific`. Extensible — new categories are new enum cases, not a schema change. |
| `restricted_space_id` | `foreignId`, nullable, `-> spaces` | Set only when `category = space_specific`. A transaction validator (not a DB constraint) enforces this pairing. |
| `source` | `string` + PHP backed enum, default `top_up` | `top_up`, `subscription_grant`, `gift`, `company_admin_allocation`. **Documentation only** — never read by spend-resolution logic, only by reporting/audit. |
| `expires_at` | `datetime`, nullable | See expiry rule below. |

All four are optional at the model level; a transaction that sets none
of them is today's plain top-up, unchanged.

### `wallet_transaction_allowed_users` — new pivot table

| Column | Type |
|---|---|
| `wallet_transaction_id` | `foreignId -> wallet_transactions` |
| `user_id` | `foreignId -> users` |

**No rows for a transaction means unrestricted** — any member of the
same wallet (an individual's own wallet, or any `company_members` of a
company-owned wallet, per
[wallet-subscription-ownership.md](wallet-subscription-ownership.md))
can spend from it. Rows present means only those named users can.

## Spend-resolution logic

On any debit:

1. Look for a balance matching the requested category, that is not
   expired, and that is not restricted to someone other than the
   spending user (no `wallet_transaction_allowed_users` rows, or the
   spending user is among them).
2. If none is found, fall back automatically to the general balance.

This resolution order is the entire spending rule — `source` never
enters it.

## Expiry and rollover

**Every categorized or restricted balance expires at the end of the
billing cycle it was granted in, regardless of `source`.** A
`company_admin_allocation` expires exactly like a `subscription_grant` —
this is the same "no rollover" principle §5.2 already applies to plan
allowances, generalized to every non-general balance. **Only the
general balance rolls over.**

## Routing for a user with both a personal wallet and a company membership

Hybrid, not one fixed rule:

- **Automatic** when only one valid balance exists at the moment of
  spend (e.g. the only available credit is a restricted
  `company_admin_allocation` with no personal alternative).
- **Explicit choice** when more than one valid balance exists
  simultaneously (e.g. a personal general balance and a company general
  balance both cover the request) — the user picks.

Every receipt/transaction record states precisely which wallet was
debited — personal, or `Company X` plus the category if one applied.
This is a UI/API responsibility on top of the schema above, not a new
column: the debit transaction already links to the wallet it came from,
and the wallet already links to its `owner_type`/`owner_id`.

## Relationship to the existing "no rollover" and ownership decisions

This design doesn't reopen [wallet/subscription
ownership](wallet-subscription-ownership.md) (`owner_type[user|company]`
stays as decided) or PRD §5.2's no-rollover rule — it extends the
no-rollover principle from "plan allowances only" to "every
categorized/restricted balance, whatever its source," and gives the
existing `wallet_transactions` table the columns needed to express that.

## What Phase 3 needs to check when it arrives

- Does `restricted_space_id` need a guard ensuring it is only set when
  `category = space_specific` (a model-level validator, most likely —
  not enforceable as a DB constraint across two nullable columns)?
- Does the spend-resolution logic belong in a `Domain\Membership` (or
  `Domain\Finance`) service class, given it will be called from booking,
  walk-in settlement, and the café/printing flows alike?
- `category` and `source` are two more enum-shaped columns — they get
  `string` + PHP backed enum casts per §A.4, and an entry in
  [`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php)
  once the models exist.
