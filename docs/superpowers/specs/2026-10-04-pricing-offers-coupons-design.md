# Design: Sale Prices, Coupons and One Pricing Pipeline (Pricing Phase 1)

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-10-04
**Depends on:** `feat/add-point-system` (unified payments, plan discounts,
`price_points` snapshots) + `fix/unified-payments-review` (`Booking::pointsPaid`,
extension adds only the difference, plan discount used up by every booking it
priced).

## Context

Two kinds of reduction are needed across bookings, co-working sessions,
packages (plans) and paid events:

1. **A time-boxed sale price** on the item itself — "this package is 120,
   but 100 for two days", "this room's hourly rate is lower for a week".
2. **Coupons** — a code the member types, whose scope (what it applies to)
   and audience (who may use it) differ from one coupon to the next.

Later phases build on the same pieces: a **paid gift code** (Phase 2) and
**referrals** that reward both sides (Phase 3).

Today a price is composed by hand at roughly six call sites —
`AmountCalculator::forRange()` plus `PlanDiscountService` in booking
creation, batch creation, extension, preview, check-out closure, and plan
purchase. Adding a sale and a coupon at each of them would restate the same
rules six times. This phase introduces **one pricing pipeline**,
`App\Domain\Pricing\Services\PriceQuoteService`, the single place a price is
composed — the same "the rule lives once" shape as `AvailabilityService`
— and a guard test that keeps it that way.

## Decisions (made with the product owner, 2026-10-04)

| Topic | Decision |
|---|---|
| Shape of offers | Sale-price **columns on the item** (one sale per space/plan at a time), not a generic promotions table |
| Space sale timing | **Usage time**: the seconds of a booking/session that fall inside the sale window are priced at the sale rate, the rest at the normal rate, rounded up **once** over the whole |
| Plan sale timing | Evaluated **as of when the payment is opened** |
| Stacking | The member gets the **better** of (sale price, plan discount) — never both — and a coupon applies **on top** of that. A coupon can opt out (`combinable_with_offers = false`) |
| Coupon scope | Per coupon: no targets = every payable purpose (booking, co-working session, plan purchase, event registration; **never** a wallet top-up); or targets naming a purpose and optionally one plan / space / space type / event |
| Coupon audience | Per coupon, composable: unrestricted; named members; new members only; one company's members; total `usage_limit` ("the first ten"); `per_user_limit`; `min_amount_points`; `max_discount_points`; validity window; active flag |
| Phases | (1) sales + coupons + pipeline, (2) paid gift codes, (3) referrals — separate specs and PRs; Phase 1's tables grow by added columns only |

## Defaults taken in planning (cheap to reverse; flagged)

| # | Question | Default |
|---|---|---|
| D1 | A coupon on a multi-date batch | One use per window, as far as the limits allow |
| D2 | A non-combinable coupon when the offer is better | The purchase proceeds at the offer price; the response reports `coupon.applied = false`, reason `offer_is_better` (not a 422) |
| D3 | `min_amount_points` compares against | The price the coupon applies to (after offers) |
| D4 | "New member" | No accepted, unrefunded payment of more than 0 points for anything but a top-up; no paid booking/session/registration priced above 0; no live use of another new-members-only coupon. A top-up alone, or a membership staff granted, is not a purchase |
| D5 | Coupons on a company plan purchase, or entered at the reception desk | Refused in Phase 1; the services accept a coupon so either is a small follow-up |
| D6 | A reversed plan payment / a no-show booking | Reversed gives the use back; a no-show keeps it |
| D7 | Extending a booking priced by a sale or a plan discount | Keeps the booking's locked choice; best-of is not re-run; no coupon on the extension |
| D9 | Plan coverage of a session that crosses a sale window | Covered seconds come off the end (today's convention) |
| D10 | A final price of 0 | Booking / walk-in: unpaid at 0 = nothing owed (today's meaning). Event registration: Paid (the door refuses Unpaid). Plan: settled at once through a free path |
| D11 | Probing codes | An inactive or unknown code answers the same `not_found` |

## Pricing algorithm — the one copy

```
L = list price   = AmountCalculator::forSegments([[seconds, rate]])   (forRange delegates; ceil once)
S = sale price   spaces: forSegments(SpaceRateTimeline::segments(space, from, to))
                 plans:  sale_price_points when sale_starts_at <= asOf < sale_ends_at, else L
P = plan price   = L − floor(L · pct / 100)            (bookings only; PlanDiscountService)
A = min(S, P)    a tie takes S (it spends no plan-discount usage); choice ∈ {list, sale, plan}
d(x)             percentage: floor(x · v / 100) | amount_off: v
                 then min(d, max_discount_points), then min(d, x)

combinable coupon:      base = A, A >= min_amount_points else min_amount_not_met;  final = A − d(A)
non-combinable coupon:  Y = L − d(L) (L >= min_amount_points);
                        Y < A  → choice = list, the coupon applies to L
                        else   → the coupon is not used (offer_is_better), no redemption
final >= 0. A PlanDiscountRedemption is written only when the choice is `plan`.
```

Inputs per purpose: walk-ins and co-working sessions get L and S over their
billable range (plan coverage is unchanged); events get L only; plans get L
and S, never P. Integer bcmath throughout; discounts floor, time prices ceil
once. VAT (`booking.vat_percent`) stays out of the charged total, as today.

## Schema (one migration per concern)

1. `spaces`: `sale_hourly_rate_points` (unsigned int, nullable),
   `sale_starts_at`, `sale_ends_at` (datetime, nullable, UTC).
2. `plans`: `sale_price_points`, `sale_starts_at`, `sale_ends_at`.
3. `coupons`: `code` (string 40, unique, stored upper-case), `name`/
   `description` (json, nullable), `source` (string, default `admin`),
   `discount_type` (`percentage` | `amount_off`), `discount_value`,
   `max_discount_points`, `min_amount_points`, `combinable_with_offers`
   (default true), `usage_limit`, `per_user_limit`, `new_members_only`,
   `company_id` (restrict on delete — nulling it would widen the audience),
   `starts_at`, `ends_at`, `is_active`, `created_by`.
4. `coupon_targets`: `coupon_id` (cascade), `purpose`, `plan_id` / `space_id`
   / `event_id` (restrict) or `space_type`; at most one item, and the item
   must match the purpose (model guard).
5. `coupon_user`: the named members a coupon is restricted to.
6. `coupon_redemptions`: `coupon_id` (restrict), `user_id`, `purpose`, one of
   `booking_id` / `walkin_session_id` / `event_attendee_id` / `payment_id`
   (nullable, unique; exactly one set — explicit FKs like
   `plan_discount_redemptions`, not a morph), plus a **snapshot of the terms**
   (`discount_type`, `discount_value`, `max_discount_points`, `base_points`,
   `points_discounted`) so editing a coupon never changes a price already
   locked.
7. `list_price_points` on `bookings`, `walkin_sessions`, `event_attendees`,
   `payments`, backfilled. Display only — refunds keep reading payment rows.
   A historical breakdown is `list − final`, split into the plan-discount
   row, the coupon row, and the remainder as the sale saving.

## Live usage instead of voiding

`CouponRedemption::scopeLive()` derives whether a use still counts from the
record it is attached to: a booking in a slot-holding status, a registration
in a seat-holding status, a payment that is Pending / Accepted / NeedsReview,
a walk-in always. Cancelling, rejecting, a cash hold expiring, a card
failing, a payment reversed — all give the use back with no `voided_at`
column and no hook on any of the ~9 exit paths (several of which are
query-builder `update()`s that fire no model events).

## Locking

The coupon row is locked **first** in every transaction that redeems it.
Under MySQL REPEATABLE READ the snapshot is taken at the first plain read,
so locking it late would count usage from a stale snapshot and let "the
first ten" become eleven; locking it after Branch/Space would also deadlock
against a plan purchase (which activates and locks Branch later). Global
order: **Coupon → Booking (extension) → Branch → Space / Event → Payment →
Wallet**. The code is resolved and validated before the transaction for an
early, friendly 422. SQLite (the test database) ignores `lockForUpdate`, so
this protocol is documented and reviewed by hand.

## Entry points

**Member**
- Optional `coupon_code` on `POST bookings`, `bookings/batch`,
  `bookings/preview`, `POST memberships` (wallet / cash / card; refused with
  `company_id`), `POST payments` (a plan, or applied to an existing unpaid
  payable), `POST events/{event}/register`, and `GET payment-methods`.
- `POST member/coupons/preview` — code + purpose + one of `plan_id` |
  `space_id` + `start_at` + `end_at` | `payable_id` | `event_id`; answers the
  price breakdown, or 422 with a specific `api.coupon.*` reason.
- A `sale` block (original label, sale label, ends label) on space and
  package browse/detail — the member surface returns labels only.
- `BookingPresentationService::breakdown()` renders from a `PriceQuote`,
  gaining `sale` and `coupon` lines beside `base` and `discount`.

**Admin**
- Sale fields through the existing `StoreSpaceRequest` / `StorePlanRequest`:
  all three or none, `sometimes`, "sale < normal" checked in an after-hook
  against the sent or stored value, local times via `InterpretsLocalTimes`.
- `admin/coupons` — `CouponController extends AdminResourceController` so
  `coupons.*` permissions are derived; paginated, filtered index;
  `GET admin/coupons/{coupon}/redemptions`; reads for admin|operations,
  writes `role:admin`; deleting a used coupon answers 409; audited through
  `LogsSensitiveActions`.

## Special cases

- **Plan purchase.** `PlanPurchaseFulfiller::pointsDue()` becomes
  `PriceQuoteService::forPlanPayment($payment)`: the plan re-read fresh
  (a base reprice is still caught), the sale evaluated as of
  `payment->created_at`, the coupon from its redemption snapshot.
  `PaymentService::start()` orders its work: lock coupon → `assertPayable`
  → save → record redemption → `amount_points` → driver. `StartPayment`
  prices through the pipeline; `PaymentAmountCalculator::forPlan()` goes.
  `OutstandingItems::heldPlans()` shows the fulfiller's figure.
- **Extension** is priced at the booking's locked automatic choice, never
  with a coupon, and adds to `price_points` and `list_price_points`.
- **Closure** (walk-in, or an unpaid booking) goes through `forClosure()`
  with the locked coupon terms; `price_points`, `list_price_points` and
  `amount_owed` are written together by `PriceQuote::attributes()`.
- **A coupon on an existing payable** is refused while a payment for it is
  pending, once anything was paid toward it, when it already carries one,
  or when a non-combinable coupon meets a price set by an offer.
- **A card payment for 0 points** is never started (today it 500s).

## Prerequisite fix

`Admin\PlanController::update()` deleted and recreated `plan_discounts`;
`plan_discount_redemptions` cascades on that delete, so editing a plan wiped
the usage history this phase reads a booking's locked choice from. Discounts
are now reconciled by `id`; one a booking was priced with is deactivated,
never deleted.

## Phases 2 and 3 (sketch — own specs)

- **Gift codes:** `CouponSource::Gift`; `coupons.owner_user_id`,
  `recipient_phone`, `issued_by_payment_id`; `PaymentPurpose::GiftPurchase`
  whose fulfiller creates a single-use 100% coupon targeting
  `plan_purchase` + the plan with a random code; one eligibility rule
  (recipient phone matches). Redemption is the ordinary plan path through the
  free path; a gift payment reversed before use deactivates the coupon.
- **Referrals:** `CouponSource::Referral`; one coupon per member
  (`owner_user_id`, `new_members_only`, `per_user_limit = 1`), terms from
  `referral.*` settings; rule "not your own code"; a `PaymentSettled` event
  after commit; a listener that writes `referral_rewards` (unique per
  invitee) and credits the inviter with `WalletTransactionSource::ReferralReward`.

## Testing

Unit: enums, `SaleWindow`, `AmountCalculator::forSegments`,
`SpaceRateTimeline`, `CouponCalculator`, lang keys. Feature: one test file
per service (`PriceQuoteBookingTest`, `CouponLiveUsageTest`,
`CouponEligibilityTest`, `BookingCouponCreationTest`, `BookingSaleCreationTest`,
`BookingExtensionPricingTest`, `PlanSalePriceTest`, `PlanCouponPurchaseTest`,
event registration, coupon preview, browse labels, admin CRUD). Guard:
`tests/Guards/PricingIsCentralizedTest` — no `->forRange(` or plan-discount
`apply`/`resolveFor` outside Pricing and the legacy fallbacks, no
`ceil`/`floor`/`round` inside `app/Domain/Pricing`, no `activate(` relying
on the default `charge: true`.

## Out of scope

Percentage or bulk sales across many spaces at once (one sale per item);
coupons at the reception desk and on company purchases (D5); upcoming-sale
announcements; VAT on the charged total; gift codes and referrals (Phases 2–3).
