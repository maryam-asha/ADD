# Sale prices, coupons and one pricing pipeline

**Status:** resolved 2026-10-05; **sales superseded 2026-10-06** by
automatic offers — see `docs/decisions/automatic-offers-savings-discount-ledger.md`.
The per-item sale columns below were dropped before reaching production,
and `coupon_redemptions` became `applied_discounts`. **Owner:** Maryam Asha.
**Type:** design doc for a new capability (Pricing phase 1 of 3). Design:
`docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md`; plan:
`docs/superpowers/plans/2026-10-04-pricing-offers-coupons.md`. This file
describes the code as shipped, including the rulings made while
implementing it.

## What this adds

- **Sales on the item.** A space's hourly rate or a plan's price can be on
  sale for a window: `spaces.sale_hourly_rate_points` / `plans.sale_price_points`
  plus `sale_starts_at`/`sale_ends_at`. One sale per item at a time — "the
  package at 100 instead of 120 for two days", "the room at 80 an hour for a
  week".
- **Coupons.** A code a member types. Per coupon: a percentage (capped by
  `max_discount_points`) or a number of points off; what it is for
  (`coupon_targets`; none = every purchase a coupon can be spent on, never a
  wallet top-up); who may use it (named members, a company's members, new
  members only), how often (`usage_limit` = "the first ten", `per_user_limit`),
  a minimum price, a validity window, an active switch, and whether it stacks
  on an offer (`combinable_with_offers`).
- **One pricing pipeline.** `App\Domain\Pricing\Services\PriceQuoteService`
  composes every price — booking, batch, extension, preview, session
  closure, plan purchase, event registration — and returns a `PriceQuote`
  that knows its list price, what the automatic choice was, what the coupon
  took and the lines a breakdown shows. Before it, the list price and the
  plan discount were stitched together by hand in six places.

Phases 2 (paid gift codes) and 3 (referrals) build on these tables by
adding columns only — see the spec.

## The algorithm — the one copy

```
L = list price         AmountCalculator (time rounds up once)
S = sale price         spaces: by usage time — the seconds inside the window at the sale rate,
                       the rest at the normal rate, rounded up once over the whole;
                       plans: the sale price if live when the payment opens, else L
P = plan discount      bookings only: PlanDiscountService, unchanged
A = min(S, P)          the better for the member; a tie goes to the sale (it spends no plan-discount use)
coupon (combinable)    on A, if A >= min_amount_points: final = A - d(A)
coupon (not)           on L instead, if that is lower than A; otherwise not used (offer_is_better)
d(x)                   percentage: floor(x * v / 100), capped by max_discount_points; amount_off: v; never above x
```

Integer points and bcmath throughout: time rounds up, discounts round down,
nothing is a float. VAT stays outside the price, as before.

What enters each purpose: a booking has L, S, P and a coupon; a walk-in
or co-working session L, S and a coupon (plan coverage still comes off the
end of the session); a plan L, S and a coupon; an event L and a coupon.

## Live usage instead of voiding

A use is a `coupon_redemptions` row on exactly one record (`booking_id`,
`walkin_session_id`, `event_attendee_id` or `payment_id`), holding a copy of
the terms it was priced on (`discount_type`, `discount_value`,
`max_discount_points`, `base_points`, `points_discounted`). Editing a coupon
never changes a price already locked.

Whether a use still counts is read from the record, not stored
(`CouponRedemption::scopeLive`): a booking holding its slot, a registration
holding its seat, a payment that is pending, accepted or in review, any
walk-in. Cancelling, rejecting, a failed card, an expired cash hold and a
reversed payment all give the use back with no hook — several of those paths
are bulk updates that fire no model events. A no-show keeps it.

## Locking

The coupon row is the **first** lock in any transaction that redeems it —
otherwise "the first ten" reads a stale snapshot under MySQL's REPEATABLE
READ, and a plan purchase can deadlock against a booking. The global order:
Coupon → Booking (extension) → Branch → Space/Event → Payment → Wallet. The
code is resolved and checked before the transaction too, so the member gets
a clear 422 early. Tests run on SQLite, where `lockForUpdate` is a no-op, so
this protocol is held by review, not by a test.

## Entry points

**Member** — `coupon_code` (optional) on `POST bookings`, `bookings/batch`,
`bookings/preview`, `memberships`, `payments` (a plan, or an existing unpaid
booking / session / registration), `events/{event}/register` and
`GET payment-methods`. `POST member/coupons/preview` ("Apply" in the app,
throttled 20/min) prices without writing. A code that cannot be used answers
422 `{message, reason}` with a `CouponRejection` reason. Bookings, plan
purchases and payments return `coupon: {code, applied, points_discounted,
message}` beside `data`; a registration's price after the code is its own
`price_points`. A live sale shows as a
`sale` block (original and sale price, how much it takes off — points,
amount and a percentage rounded down, with a ready badge — and when it
ends) on the space and package listings and details.

**Admin** — the sale fields on the existing space and plan forms: all three
or none, local times, below the price sent or stored. `admin/coupons` CRUD
(`CouponController` extends `AdminResourceController`, so `coupons.*`
permissions are derived): reads for admin and operations, writes for
`role:admin` only, every write audited (`coupon_created` / `coupon_updated` /
`coupon_deleted`). `GET admin/coupons/{coupon}/redemptions` lists who used it
and whether each use still counts. A used coupon is deactivated, not deleted
(409).

## Special cases

- **A final price of 0.** A booking or walk-in stays unpaid at 0 — nothing
  owed. An event registration is marked paid (the door refuses unpaid). A plan
  settles at once through a free path in `PaymentService::start`, with no
  gateway or cash hold.
- **A plan purchase** is priced by the fulfiller from the plan as it is, the
  sale as of `payment.created_at` and the coupon from its locked copy —
  so a sale that ended while the card page was open is still honoured.
- **An extension** keeps the booking's locked choice (plan discount, else the
  sale by usage time), adds to `price_points` and `list_price_points`, and
  never takes a coupon.
- **A registration a coupon made free** is confirmed paid; if the event
  needs approval, approving it charges nothing.
- **A coupon on something already owed** is refused while a payment for it
  is pending, once anything was paid toward it, or if it already has a code.
- **`list_price_points`** on bookings, walk-ins, registrations and payments
  is for display: savings = list − final. Refunds still come from `payments`.

## Rulings

- **Sales never raise a price.** A sale rate at or above the normal rate is
  ignored by pricing, and refused by the admin forms.
- **`not_found` for an unknown or inactive code** (D11) — probing codes
  learns nothing.
- **`new_members_only` has its own reason** (`new_members_only`), clearer than
  `not_eligible`.
- **A non-combinable coupon beaten by the offer** does not fail the purchase
  (D2): it goes through at the offer price with `applied: false` and the
  `offer_is_better` message.
- **A coupon on a batch** is used once per window while its limits allow;
  dates it cannot cover are booked without it, and the request is refused
  only if no date could use it (D1).
- **The breakdown keeps the plan discount's line key `discount`** for the
  app; the new lines are `sale` and `coupon`.
- **A cash plan purchase with a coupon opens its own hold** rather than
  reusing an open one priced without it.
- **Package sale labels are in the base currency**, like the package's own
  `formatted_price`.
- **The raw sale fields are on admin routes only** — a scheduled sale is not
  announced on the public plan listing early.
- **A stored sale that has already ended** does not stop the admin lowering
  the price.
- **`per_user_limit` defaults to 1** when the admin leaves it out on create;
  `null` means unlimited.
- **Coupon targets are replaced wholesale on update** — they carry no
  history; uses keep their own copy of the terms.
- **Coupons at the reception desk and on company plan purchases are refused
  for now** (D5); the services already take a coupon, so either is a small
  follow-up.

## Deploying

1. Migrate: `2026_10_05_100000`–`100700` add the sale columns, the four
   coupon tables and `list_price_points`, then backfill `list_price_points`
   — a booking's price plus its plan discount, anything else's price (the
   only discount that existed before).
2. `php artisan permissions:sync` — derives `coupons.view/create/update/delete`
   and gives them to `admin`.
3. **Tell the app team:** price a booking with `bookings/preview`, never
   `rate × duration` — a sale can cover part of a booking. Show the `sale`
   block where present; send `coupon_code` with the purchase; use
   `coupons/preview` for the "Apply" button.

## Explicitly not done here

- Coupons at the reception desk and on company purchases (D5).
- Bulk or percentage sales across many items; announcing upcoming sales.
- Gift codes and referrals — phases 2 and 3, own specs.
- VAT on the charged total (unchanged).

## Guards and tests

- `tests/Guards/PricingIsCentralizedTest` — time pricing, plan-discount
  choice and coupon arithmetic only inside `app/Domain/Pricing` (plus three
  named legacy fallbacks); no float rounding there; every membership
  activation passes `charge: false`.
- `tests/Feature/Pricing/*` — one file per service and entry point;
  `tests/Unit/Domain/Pricing/*` — the value objects, calculator and lang keys.
- `DomainLayerBoundaryTest` and `EnumColumnsHaveBackedEnumCastsTest` cover
  the new domain and its enum columns.
