# Plan discounts: a structured, computable child table

**Status:** resolved 2026-09-20, at implementation time. **Owner:** Maryam Asha.

`plan_balances` was already a structured child of `Plan` — `category`,
`amount_points`, `restricted_space_id` are real columns `WalletService` reads
directly. `plan_features` stayed pure marketing text (`name`/`value` bilingual
JSON strings), and one recurring shape of feature text — "خصم 30% على غرف
الاجتماعات ومساحات الفعاليات" ("30% off Meeting Rooms and Event Spaces") — was
never parsed into anything a booking's price actually used. This doc records
the third child table, `plan_discounts`, that makes that sentence a real,
enforced discount, and the rules it enforces by.

## Schema

**`plan_discounts`**: `plan_id` FK · `percentage` (unsigned tinyint, 1-100) ·
`space_id` (nullable FK→spaces) · `space_type` (nullable string, a `SpaceType`
enum value) · `usage_limit` (nullable unsigned int — `null` means unlimited)
· `description` (nullable JSON, i18n, display-only — same role as
`plan_balances.description`) · `is_active` (bool, default true) · `order`
(int, matches the existing `hasOrderColumn` convention).

**`plan_discount_redemptions`**: `plan_discount_id` FK · `membership_id` FK ·
`booking_id` FK · `points_discounted` (unsigned int). One row per booking a
discount was actually applied to.

## `space_id` and `space_type` are mutually exclusive

A discount may target one specific `Space`, one whole `SpaceType` (e.g. every
`room`), or neither (a plan-wide discount that applies to any space) — never a
space and a type at once, since a specific space already implies its type.
`PlanDiscount::booted()` throws `InvalidArgumentException` on `saving()` if
both are set, mirroring the guard `WalletTransaction::booted()` already
enforces between `restricted_space_id` and `category`.

## `usage_limit` is a ledger, not a stored counter

The same principle `WalletService` already applies to balances — no cached
running total, only append-only rows summed/counted at read time — applies
here. `plan_discount_redemptions` is that ledger: usage is enforced by
counting rows scoped to `(plan_discount_id, membership_id)`, never by
decrementing a `usage_limit` copy on the discount row itself. This is also
what makes a plan renewal free of any manual reset: a renewal creates a new
`Membership` row, and the count naturally starts back at zero for the new
`membership_id` — there is nothing to zero out by hand, the same way an
expired `wallet_transactions` credit simply stops being counted rather than
being swept by a job.

## Specificity precedence, no stacking

`PlanDiscountService::resolveFor()` picks at most one discount for a given
`(Membership, Space)` pair — discounts never stack. Candidates are filtered to
`is_active` and a matching target (exact `space_id`, else matching
`space_type`, else a plan-wide row with both null), then ranked by
specificity: an exact space match beats a space_type match, which beats a
plan-wide discount. The most specific candidate whose `usage_limit` has not
yet been exhausted for this membership wins; if the most specific candidate is
exhausted, the next-most-specific one is tried, not silently skipped to no
discount.

## Rounding direction matches the "never undercharge" rule

`PlanDiscountService::apply()` computes
`finalPoints = rawPoints - floor(rawPoints * percentage / 100)`, flooring the
*discount amount* (not the final charge) with integer bcmath — the same style
`AmountCalculator` already uses for the undiscounted rate. Flooring the
discount, rather than the final points, means the member never receives more
than the percentage strictly grants; the booking is never undercharged by a
rounding artifact.

## Applied automatically, in the booking pricing path only

Confirmed requirement: a discount is not an opt-in the member triggers. It is
resolved and applied inside `BookingCreationService::create()`, right after
`AmountCalculator::forRange()` computes the raw point cost and before payment
is routed — `routePayment()`, the booking's `amount_owed`, and the eventual
`PlanDiscountRedemption` row all use the discounted amount, not the raw one.
`AmountCalculator` itself is untouched and stays a pure space-rate
calculator with no membership/discount awareness — the two concerns compose
in `BookingCreationService`, not inside the rate calculator.

A `PlanDiscountRedemption` row is written for every booking the discount
priced, paid at creation or not (single and batch). Superseded: it used to be
written only when the booking's resulting `payment_state` was `Paid`, but
since unified payments (`docs/decisions/unified-payments.md`) a booking left
unpaid — cash at the desk, a card, pay-now later — still carries the
discounted `price_points` and is charged it, so not counting it let any
member bypass `usage_limit` by choosing to pay later (final review I1).
Check-out keeps the discount too: `SessionClosureService` prices the time
used at the booking's own redemption discount, so a discounted booking paid
after check-out costs the discounted price.

## Extension recomputes, cancellation/rejection void

`BookingExtensionService::extend()` checks whether the booking already holds
a `PlanDiscountRedemption`. If it does, the *additional* time is charged at
the same discounted rate as the rest of the booking — `newPoints`/`oldPoints`
are both run back through `PlanDiscountService::apply()` using the
redemption's own discount, and the wallet is only ever debited the
difference between the two discounted totals, never the raw undiscounted
delta. The redemption row's `points_discounted` is updated to the new total
so it keeps describing the booking's current duration.

`BookingCancellationService::cancel()` and `BookingApprovalService::reject()`
both refund a wallet-paid booking — and now do so for exactly the amount that
was actually charged (raw points minus the redemption's `points_discounted`,
when one exists), not the raw undiscounted rate. Both call
`PlanDiscountService::voidFor()` in the same transaction as the refund,
deleting the booking's redemption row so the usage allowance it consumed is
returned to the membership — a cancelled or rejected booking never
permanently spends a limited discount's usage.

## Admin surface

`plan_discounts` is embedded in `Plan`'s existing store/update payload next to
`balances`/`features` — same create/replace-on-update pattern, no new route,
no new permission. `PlanController`'s `store()`/`update()` extract a
`discounts` array the same way they already extract `balances`/`features`,
and `PlanResource` projects it back the same way.
