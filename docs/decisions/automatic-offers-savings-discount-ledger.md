# Automatic offers, duration savings and the discount ledger

**Status:** resolved 2026-10-06. **Owner:** Maryam Asha.
**Type:** design doc for Pricing phase 1.5. Design:
`docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md`;
plan: `docs/superpowers/plans/2026-10-06-automatic-offers-savings-discount-ledger.md`.
Builds on `docs/decisions/pricing-offers-and-coupons.md` (phase 1), whose
per-item sale columns it replaces. This file describes the code as shipped,
including the rulings made while implementing it.

## What this changes

- **Offers are coupons that apply themselves.** `coupons.is_automatic`; an
  offer has no code. It shares everything else with a coupon: targets,
  audience, limits, window, cap, minimum, and the two discount types —
  a percentage or points off. (A third, `fixed_price`, offers only, was
  dropped on 2026-10-08: on one item it is points off, and on several it
  meant a different discount per item. Migration `2026_10_08_100000`
  turned each into the points off that left its one item at that price,
  and switched off any naming several items, for an admin to set again.)
  The dashboard manages offers on `admin/coupons?is_automatic=1`.
- **The sale columns are gone** from `spaces` and `plans`, along with
  `HasSalePrice` and `ValidatesSaleWindow`. Phase 1 never reached
  production, so nothing was migrated.
- **Duration and volume savings.** A plan may name a `reference_plan_id`
  or a `reference_space_id` (its hourly rate), plus `reference_units`. Its
  saving is the reference's list price × units, less its own price. A
  saving is shown and recorded, never taken off the price.
- **One discount ledger.** `coupon_redemptions` became `applied_discounts`:
  one row per reduction on an operation, by `kind` (`offer`,
  `plan_discount`, `coupon`, `duration_saving`), at most one of each per
  record. Each row holds a copy of its terms. The report reads it, and the
  limits are counted from it.
- **A report.** `GET admin/reports/discounts`.

## The algorithm

```
L  list price                       AmountCalculator (time rounds up once)
O  best automatic offer price       OfferResolver (or L when none)
P  plan discount price              PlanDiscountService, bookings only
A  min(O, P)                        a tie takes the offer (it spends no plan-discount use)
manual coupon                       on A if combinable; else on L if lower than A (else offer_is_better)
```

`OfferResolver` gathers active automatic coupons whose targets match. It
drops those the member may not use, checking audience, new-members and
usage limits with the coupon rules, and does so silently (E4). It prices
the rest and keeps the cheapest. A tie goes to the offer ending first,
then to the lowest id.

On time in a space, an offer prices the seconds inside its window at an
exact rate and the rest at the normal rate, rounded up once.
`RateSegment` now carries a bcmath string, so 10% off 15 is 13.5. On a
plan or event, the offer takes its terms off the price, the way a coupon
does.

With no member (a staff preview), only offers with no audience restriction
apply.

## Locked terms

`QuoteRecorder` copies an offer's terms and window onto its ledger row
when the price is locked. Later prices read that copy:

- **An extension** keeps the booking's choice (D7). That is its plan
  discount, else its offer at the copied terms over the copied window,
  else the list price. It never takes a new offer.
- **A check-out** (a walk-in, or an unpaid booking) uses the locked offer.
  When there is none and no plan discount either, it picks the best offer
  over the time actually used and locks it then.
- **A plan payment** is due at the offer locked when it was opened.

Editing or switching off an offer never moves a price it already set.

## Locking

`CouponLedger::lockForPricing($coupon)` is the first lock and the first
read in every transaction that may spend an offer or a coupon: booking
creation and batch, event registration, plan purchase (`PaymentService`,
`StartPayment`) and session closure. In one locking read, it takes the
typed coupon plus every active offer that has a limit, in id order. It has
no relation filter, because a subquery there would be a plain read that
fixes the snapshot before the lock.

`CouponApplication` keeps `lockForRedemption()`: no offer is chosen there.

## Savings

`PlanSavingCalculator` returns a `PlanSaving`:

- `reference_points` = the reference's **list** price × units (E7)
- `saving_points` = `reference_points` − the plan's price
- `saving_percent`, rounded down
- `per_unit_points`, exact

A non-combinable coupon still applies on a plan with a saving, because a
saving is not an offer.

## What the member sees

- **`sale`** (spaces, packages and events): the best offer live **now for
  this member**, so a members-only offer shows only to its members. Same
  shape as before. On an event card it is null for a free event and once
  the member is registered — their price is locked (2026-10-07,
  `EventSalePresenter`, outside the domains because Experience may not
  import Pricing). `ends_at` and `ends_label` are null for an offer with no end.
  A rate with a fraction of a point is shown rounded **up** (E8); the
  charge is exact.
- **`saving`** (packages): `{compared_to, reference_points,
  reference_label, saving_points, saving_percent, saving_label,
  saving_description (2026-10-08: the saving as one sentence — "Get 12
  months for 90 $ (regular price 150 $) — save 40% (600 pts). That's
  75 pts/mo." — counted in the reference's unit: hours for a space, else
  the reference plan's period when it is a day, a week or a month, else
  named by the reference plan; the unit rate in points, never money (user
  ruling: no fractions); a day pass compared with a space's hours reads
  "a full day … for 8 hours", with no unit rate; `saving_label` stays the
  badge),
  per_unit_label}`, or null. `per_unit_label` is rounded up to a whole
  point, like an offer rate.
- **Breakdown lines** keep their keys: `sale` (now labelled with the
  offer's name, or "Offer"), `discount`, `coupon`.
- **`discounts`** on a booking (member detail, dashboard list and detail),
  a payment, a co-working session and an event registration: each ledger
  row as it was locked — `kind`, `offer_id` / `coupon_id` /
  `plan_discount_id`, the `name` and `code` copied then, the terms, and
  `base_points` / `points` / `percent`. Renaming or changing the offer
  later does not rewrite it (`AppliedDiscountPresenter`; a list loads the
  rows of a page in one query). Not a relation on the models: an event
  registration is Ecosystem, which may not import Pricing.
- **`GET member/coupons`**: the codes given to this member by name or
  through their company, usable now, optionally for one purpose and item.
  A code given to no one in particular is still never listed (D11).

## What the admin sees

- **Offers** use the coupon CRUD with `is_automatic: true` and no `code`
  (sending one is a 422). An update never turns a coupon into an offer or
  back.
- **Plans** take `reference_plan_id` / `reference_space_id` /
  `reference_units`. Exactly one reference or neither; units are required
  with a reference and refused without one; a plan cannot reference itself.
  Admin plan routes return the computed `saving`.
- **`GET admin/reports/discounts`** (admin and operations): `from`/`to`
  (local dates), `kind`, `purpose`, `status` (`counted`|`void`) and
  `per_page`. Each row is an operation with all its discounts and its
  saving, its record status, and `counted`. `meta.totals` covers counted
  operations only: count, list, final, points per kind,
  `effective_percent` (rounded down), and `saving_points` totalled apart.
- **Offer history.** Every create, change and delete of an offer or a
  coupon on the dashboard writes a `coupon_revisions` copy
  (`CouponHistory`; no foreign key, so a deleted offer keeps its history).
  `GET admin/coupons/{coupon}/revisions` lists them newest first.
  **`GET admin/reports/offers`** (`from`/`to` local dates, required;
  `type=coupon` for coupons) lists what ran in the period: each stretch it
  was switched on and inside its own dates, clipped to the period, on the
  terms it had then (a change that kept the terms — a new name — does not
  split a stretch), plus `operations` and `points` from the ledger's
  counted rows in the period. Coupons that existed before the table get one
  copy of what they are now, dated when they were created: their earlier
  states are not known.

## Rulings made while implementing

- `CouponLedger::record()` stays as the coupon-kind shortcut beside
  `write()`. Every write to `applied_discounts` goes through `CouponLedger`
  (`PricingIsCentralizedTest`).
- A locked offer row is resized to 0 when the time actually used fell
  wholly outside its window, so the breakdown still adds up.
- `SessionEndService::confirm()` holds the session row before `closeOut()`
  locks the offers. Closure touches no branch or space, so there is no lock
  cycle with booking creation.
- `PaymentService` settles a plan at once when the quote took something
  off and nothing is due. This covers a 100% offer as well as a 100% coupon.
- A coupon's code may be left out on update. Offers are searched by name.

## Deploying

1. Back up first. Migrations `2026_10_06_100000`–`100400` make the coupon
   code nullable and add `is_automatic`; rename the ledger table and
   backfill it (percent, plus a `plan_discount` row per plan-discount
   redemption); drop the sale columns; and add plan references.
   `2026_10_07_100000` adds `coupon_revisions` and gives every existing
   coupon its first copy.
2. **Tell the dashboard team:** remove the sale fields from the space and
   plan forms; add an Offers screen on `admin/coupons?is_automatic=1`, the
   plan reference fields, and the report.
3. **Tell the app team:** `sale` is now per member, so send the token;
   `ends_at`/`ends_label` may be null; read the new `saving` block.

## Not done here

Admin-set priorities between offers; announcing upcoming offers; CSV
export of the report; coupons at reception and on company purchases
(phase-1 D5); gift codes and referrals (phases 2 and 3).
