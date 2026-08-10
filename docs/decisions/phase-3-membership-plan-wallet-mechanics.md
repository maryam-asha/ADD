# Phase 3 mechanics: base schema and the debit-resolution algorithm

**Status:** resolved 2026-08-09, at implementation time. **Owner:** Maryam Asha.

[wallet-subscription-ownership.md](wallet-subscription-ownership.md) and
[wallet-points-categorization.md](wallet-points-categorization.md) fix the
*shape* of `owner_type`/`owner_id` and the categorization columns, but neither
is a full schema for `plans`/`memberships`/`wallets`, and neither specifies
how a debit is actually resolved when more than one categorized/restricted
grant of the same category coexists in one wallet. Both gaps had to be closed
to write the first migration. This doc records what filled them, since no ERD
source document exists in this repo to point to instead.

## Base schema (not specified anywhere else)

**`plans`** (public catalog, two-tier Admin/Public controller like `Founder`):
`name` (i18n JSON, `HasTranslations`) · `is_subscription` (bool — build plan
§Phase 3 guard: `false` can never create a `memberships` row; such plans are a
single-use Hot Desk package that creates a `booking` directly, Phase 5) ·
`price` (decimal 10,2) · `pricing_currency` (string, per
[money-model.md](money-model.md)) · `duration_days` (int — the "duration
permit" from PRD §5.2's hybrid pricing) · `included_hours` (decimal 8,2,
default 0 — the included-room-hours half of §5.2; granted as a
`wallet_transactions` credit, `category=space_specific`, on purchase) ·
`overage_rate` (decimal 10,2, nullable — the discount/rate for usage past
`included_hours`; stored here as a catalog attribute only, not applied by any
logic yet — booking/overage billing is Phase 5) · `is_active` (bool, default
true) · `order` (int, matches the existing content-resource `hasOrderColumn`
convention).

**`memberships`** (ERD v2.0 naming, the actual purchase record): `plan_id` FK
· `owner_type`/`owner_id` (manual polymorphic, `Consent` pattern — no FK on
`owner_id`, indexed as a pair) · `status` (backed enum: `active`, per the
usual convention — no cancellation/expiry flow exists yet, so only `Active`
is ever assigned in Phase 3; the column exists so Phase 5+ has somewhere to
put `Cancelled`/`Expired` without a migration) · `current_period_start` /
`current_period_end` (datetime). **No uniqueness constraint** on
`owner_type`+`owner_id` — unlike wallets, an owner can hold more than one
concurrent membership (e.g. a Dedicated Desk subscription and a separate
room-hours package). Renewal/recurring re-billing is out of scope — Phase 3
only creates the first cycle; nothing here schedules a second one.

**`wallets`**: `owner_type`/`owner_id` (same manual-polymorphic pattern),
unique **together**. No `balance` column — see "no stored balance" below.

**`wallet_transactions`**: `wallet_id` FK · `amount` (decimal 10,2, **signed**
— positive is a credit/grant, negative is a debit) · `description` (string,
nullable, free-text audit label) · the four categorization columns from
[wallet-points-categorization.md](wallet-points-categorization.md)
(`category`, `restricted_space_id`, `source`, `expires_at`), built in from
this first migration, per that doc's own instruction not to ship a minimal
version first.

**`wallet_transaction_allowed_users`**: plain pivot (`wallet_transaction_id`,
`user_id`), no dedicated Pivot class — nothing points at one specific row for
audit purposes the way `company_user` needs to for `CompanyPolicy`, so it
doesn't need `company_user`'s extra `id`/Pivot-class treatment.

## No stored `wallets.balance`

A cached balance column can only be correct if every write that changes it is
accounted for — but expiry here is a *lazy read-time check*, not a write (see
below), so a cached number would silently go stale the instant a categorized
grant's `expires_at` passes with no corresponding transaction. Instead,
"available balance for category X" is always computed by summing
`wallet_transactions.amount` for that wallet/category, filtered to
non-expired rows the requesting user is eligible for. One aggregate query per
check, no drift possible by construction.

## Expiry: lazy, at read time — no scheduled job

The instructions asked for the simplest design that satisfies "every
categorized/restricted balance dies at cycle end, general rolls over,"
explicitly leaving the choice between a periodic job and a read-time check to
implementation. Chosen: **read-time only**. Every balance query already
filters `expires_at IS NULL OR expires_at > now()` (general is always
`expires_at = NULL`, so it's untouched by this filter — that's the rollover).
Nothing needs to "zero out" a row for this to be correct: an expired row's
`amount` simply stops being counted, forever, the moment `now()` passes it.
There is no user-facing "balance" field that could show a stale expired
number, because balance is never stored — it is only ever computed at the
moment it's asked for. A periodic sweep would only be needed if something
downstream read `amount` directly without the expiry filter; nothing does.

## The debit-resolution algorithm (the part neither doc specifies)

The categorization doc states the *selection* rule ("find a matching,
unexpired, unrestricted-or-allowed balance; else fall back to general") but
not what happens when **more than one** grant of the same category, with
**different** `wallet_transaction_allowed_users` restrictions, exists in the
same wallet at once — the exact shape of "a company admin allocates cafe
credit to employee A, then separately to employee B."

A single aggregate debit row per spend is wrong here: if it's unrestricted,
it wrongly drains a pool employee B can see even when the money actually came
out of employee A's exclusive grant; if it's restricted to the spender, it
wrongly *shields* a shared unrestricted grant from ever appearing spent to
anyone else. Both break the read-time sum's correctness for someone other
than the spender.

**Resolution:** `WalletService::debit()` treats each *distinct restriction
signature* (the sorted set of allowed user ids for a transaction — empty set
= unrestricted) within a category as its own sub-pool. To spend amount `R` in
category `X` for user `U`:

1. Collect every unexpired grant (`amount > 0`) in `X` on the wallet whose
   signature `U` is eligible for (empty, or `U` is in it).
2. For each, compute its remaining balance as its own `amount` plus every
   prior debit row that carries the *identical* signature (debits copy their
   source grant's signature onto themselves — see step 3 — so this sum is
   exact, not an approximation).
3. Consume grants soonest-`expires_at`-first (spend what would otherwise be
   wasted first). For each grant partially or fully consumed, insert **one**
   new debit row (`amount` negative, `category = X`) and attach the same
   `wallet_transaction_allowed_users` rows that grant had. This is what makes
   the next lookup exact: the debit only nets against the exact sub-pool it
   actually drew from, for every future reader, not just the current spender.
4. If `R` is fully covered, done. If category `X`'s eligible pool runs out
   before `R` is covered, abandon it entirely and retry the same algorithm
   against `category = general` (general is never restricted in practice, so
   step 2/3 degenerate to the single-pool case there). If general can't cover
   it either, throw `InsufficientBalanceException` — no partial debit is ever
   left half-applied.

This uses only the schema the categorization doc already defines — no extra
linking column — because the pivot itself doubles as the attribution record.

## Company-admin allocation is a reallocation, not new money

"An internal allocation a company admin grants ... from the company's shared
wallet" (the categorization doc's own words) is read literally: it does not
create money, it earmarks existing general balance. Granting it is one
`WalletService` call that debits the company wallet's `general` balance by
the allocated amount and credits a new categorized/restricted transaction for
the same amount in the same wallet — net balance unchanged, only the
category/restriction tag changes. If unspent by `expires_at`, per the
no-rollover rule, it is simply forfeited (not returned to general) — the same
"use it or lose it" breakage the doc already applies to subscription grants.

`expires_at` for this endpoint is a **required, caller-supplied** input (an
admin-chosen date), not derived from a billing cycle — a company can hold a
wallet with no purchased membership at all (see below), so there may be no
cycle to anchor to.

## Company creation provisions a wallet only, not a membership

This turn's instructions state the company "gets its wallet/package
automatically" at creation but separately describe membership creation as
something "purchased ... via the same path as an individual purchase" —
those two statements are in tension, and no default plan/price exists for an
automatic grant to draw from. Asked for clarification; the question went
unanswered, so the reading that keeps the rest of the spec internally
consistent was taken: **`CompanyController::store` auto-creates the
company's one `Wallet` row only.** A membership is a separate, explicit
purchase through the shared purchase endpoint, exactly like an individual —
"no separate creation path" is satisfied because there is no
company-specific wallet-creation *endpoint* either; it happens inside the
existing `store()` transaction. **Flagging this rather than treating it as
settled** — if the intent was actually "grant a specific starter plan too,"
that needs a plan to grant and a decision on its price (free vs. billed to
the new contract), which does not exist anywhere in scope today.

## Individual wallet provisioning follows the same "no separate path" rule

The instructions only named `CompanyController::store` explicitly, but "every
individual and every company has exactly one wallet" is stated as a flat
invariant, and a wallet created lazily (on first top-up) would make that
invariant false for any member who never transacts. The symmetric read is
applied without asking: `MemberAuthController::verifyOtp` gains the same
one-line hook `CompanyController::store` gets, inside the existing
`wasRecentlyCreated` branch — the wallet is created exactly where the `User`
row itself first comes into existence, no new endpoint.
