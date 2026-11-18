# Resource quota: natural-unit grants alongside points

**Status:** resolved 2026-09-21, at implementation time. **Owner:** Maryam Asha.

`plan_balances` already grants a plan-holder points, restricted by
`WalletTransactionCategory` (`cafe`, `printing_internet`, `space_specific`,
...) and spent through `WalletService`. `plan_discounts`
(docs/decisions/plan-discounts-design.md) made a plan-feature discount
sentence a real, enforced percentage off a booking's point price. This doc
records the third mechanic in that family: `plan_resource_grants`, a
structured grant of a **natural-unit** quota — pages of printing, hours of
internet — that is not denominated in points at all, and its own append-only
ledger, `resource_consumptions`.

## Schema

**`resource_types`**: the admin-manageable catalog a grant points at —
`slug` (unique) · `name` (i18n JSON) · `unit_label` (i18n JSON, singular
noun: "page", "hour") · `icon` (nullable string) · `is_active` (bool,
default true) · `order` (int, matches the existing `hasOrderColumn`
convention). Deliberately not named `resources`/`Resource`: that name and
the `/admin/resources` route segment already belong to
`App\Domain\Foundation\Models\Resource`, unrelated physical equipment
metadata on a `Space` — reusing either would collide.

**`plan_resource_grants`**: `plan_id` FK (cascade) · `resource_type_id` FK
(**restrict**, see below) · `quantity` (decimal 10,2 — fractional, e.g. 5.5
hours of internet) · `description` (nullable JSON, i18n, display-only, same
role as `plan_balances.description`/`plan_discounts.description`) · `order`
(int).

**`resource_consumptions`**: `plan_resource_grant_id` FK (cascade) ·
`membership_id` FK (cascade) · `quantity` (decimal 10,2) ·
`recorded_by_user_id` (nullable FK to `users`, null-on-delete — the operator
who recorded it) · `note` (nullable string).

## A ledger, not a stored counter

Same principle as `plan_discount_redemptions`
(docs/decisions/plan-discounts-design.md), the direct precedent this table
copies: no cached running total on the grant row. `resource_consumptions`
is append-only; `ResourceQuotaService::remainingFor()` sums it at read time
with bcmath (scale 2, string accumulation, cast to float only at the
boundary — mirroring `WalletService::sumAmounts()`'s style) and subtracts
from `plan_resource_grants.quantity`, floored at `0.00` so a membership can
never show a negative remaining balance.

## Renewal resets for free

Consumption is counted scoped to `(plan_resource_grant_id, membership_id)`,
exactly like discount usage is scoped to `(plan_discount_id,
membership_id)`. A plan renewal creates a new `Membership` row, so
`remainingFor()` naturally reports the full grant amount again for that new
membership id — there is nothing to zero out by hand, and the old
membership's consumption history stays intact and untouched for audit
purposes.

## Why this is separate from `plan_balances`/`WalletTransactionCategory`

`plan_balances` grants are point-denominated and spent through
`WalletService`'s debit-resolution algorithm — they answer "how many points
can this member spend in this category before falling back to general."
`plan_resource_grants` answers a different question entirely: "how many
physical units of this resource has this member used," with no point
conversion anywhere in the mechanic. Folding this into `plan_balances`
would have meant either inventing a fake points-per-page/points-per-hour
exchange rate with no basis, or overloading `amount_points` to sometimes
mean "points" and sometimes mean "units," silently breaking every existing
`WalletService` consumer that assumes it's always points.

Concretely: `WalletTransactionCategory::PrintingInternet` already exists,
but confirmed this session to have **zero debit call sites** anywhere in the
codebase today — nothing currently spends against it, so there is no
existing points-per-page/points-per-hour rule this feature preserves or
breaks by staying separate. `plan_resource_grants` is a clean net-new
mechanic, not a migration off an existing one.

## Why Meeting Rooms hours stay out of scope

Meeting Room / Event Space hour quotas are **not** modeled as a
`resource_types` row. That case is already fully covered by the combination
of `plan_balances` (a `SpaceSpecific`-category, space-restricted point
grant) and `plan_discounts` (a percentage-off-overage discount scoped to a
`space_id`/`space_type`), both already enforced in
`BookingCreationService`'s pricing path via `WalletService::debit()` and
`PlanDiscountService::apply()`. Adding a third, natural-unit mechanic for
the same thing would duplicate an already-working path and create two ways
to answer "how many meeting-room hours does this member have left" that
could drift from each other. `plan_resource_grants` is reserved for
genuinely natural-unit resources with no existing points-based enforcement
— Printing and Internet, per the seeded catalog — not a general-purpose
replacement for the wallet's existing category/discount mechanics.

## Grant-row locking, not ledger locking

`ResourceQuotaService::consume()` opens its own `DB::transaction()` — unlike
`PlanDiscountService::redeem()`, it has no caller-held transaction to rely
on. Inside it, the **grant row** is locked with `lockForUpdate()`, not the
ledger: the very first consumption against a grant has zero pre-existing
`resource_consumptions` rows, so locking the ledger would lock nothing for
a first-time consume and let two concurrent requests both pass the check.
The grant row, unlike the ledger, is guaranteed to already exist, so it's
always there to lock. The ledger read inside the transaction is locked too,
as defense-in-depth, but the grant-row lock is what actually serializes
concurrent `consume()` calls against the same grant/membership pair.

## `resource_type_id` is `restrictOnDelete()`, not cascade

A grant's resource type is its identity — "5.5 hours of *what*" has no
meaning without it. Cascading the delete would let removing a
`resource_types` row silently prune every `plan_resource_grants` row that
points at it, and with it every `resource_consumptions` row underneath —
audit history gone with no warning. The FK blocks the delete outright;
`ResourceTypeController::destroy()` checks
`$resourceType->planResourceGrants()->exists()` first and returns a clean
422 instead of letting the constraint bubble up as a raw 500. The intended
way to retire a resource type still in use is `is_active = false`, not
deletion — the same posture `plans.pricing_currency -> currencies.code`
already takes for the same reason.

## Admin surface

`resource_types` is a small standalone admin catalog with no Public
counterpart, mirroring `PlanModule`'s shape exactly (`AdminResourceController`,
slug auto-generated from `name` with the same collision-suffix loop, no
narrower `role:admin` group). `plan_resource_grants` is embedded in `Plan`'s
existing store/update payload next to `balances`/`features`/`discounts` —
same create/replace-on-update pattern: `PlanController::store()`/`update()`
extract a `resource_grants` array the same way they already extract
`discounts`, and `PlanResource` projects it back the same way.

This inherits the same accepted tradeoff `discounts`/`balances` already
carry: editing a plan's `resource_grants` replaces every row rather than
upserting in place, which cascades into deleting `resource_consumptions`
history for any grant that gets replaced. This is not fixed here — neither
the API payload nor the (separate, ADDOS) admin form send back row ids that
would make an in-place upsert possible without a coordinated frontend
change too, so this doc simply inherits the existing precedent rather than
solving it for the first time.

## Reception surface

`POST /api/v1/admin/reception/resource-consumptions` is the manual-entry
recording endpoint reception uses at the till — same `user_id`/`company_id`
owner-resolution shape as `POST reception/wallet-top-ups`, resolving the
owner's active membership via `MembershipResolver::activeFor()` (personal)
or the new `activeForOwner()` (company), looking up the membership's plan's
matching `PlanResourceGrant` for the requested `resource_type_id`, and
calling `ResourceQuotaService::consume()`. A missing active membership, a
plan with no grant for that resource type, or a request that would exceed
what remains are all refused with a 422 naming the specific reason, not a
generic failure.
