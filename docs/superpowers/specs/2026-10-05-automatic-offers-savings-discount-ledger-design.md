# Design: Automatic Offers, Duration Savings and the Discount Ledger (Pricing Phase 1.5)

**Status:** draft for review, 2026-10-05. **Owner:** Maryam Asha.
**Builds on:** `docs/decisions/pricing-offers-and-coupons.md` (phase 1, merged
into `feat/add-point-system`, not yet in production) and branch
`feat/sale-discount-labels` (the `sale` block's discount fields).

## Context

Phase 1 stored a sale as three columns on the item (`spaces.sale_hourly_rate_points`,
`plans.sale_price_points`, `sale_starts_at`, `sale_ends_at`). Since then, four
requirements came up that those columns cannot meet:

1. **History.** A new sale overwrites the old one, and nothing on a booking or
   payment says which sale priced it or at what percentage. A report of the
   discounts given over a period is impossible.
2. **Reach.** A sale is one space or one plan. "20% off every meeting room this
   week" or "10% off every plan" needs a sale on each item by hand.
3. **Audience and limits.** A sale is for everyone, without limit. Coupons
   already have audiences, limits and targets.
4. **Built-in savings.** A 12-month plan at 900 is 75 a month against a monthly
   plan at 125, a 40% saving. A 25-hour bundle at 30 is 20% under 25 × the
   hourly 1.5. The business wants that shown and reported, but it is the
   plan's own price, not a discount.

Nothing of phase 1 is in production, so the sale columns can be replaced
without migrating data.

## Decisions (made with the product owner, 2026-10-05)

| Topic | Decision |
|---|---|
| Offers vs coupons | **One engine.** An offer is a coupon with no code that applies itself (`is_automatic`). The dashboard and the app still present "offers" and "coupons" separately |
| Overlapping offers | The **best for the member** (lowest price) applies; one offer per purchase |
| Stacking (unchanged from phase 1) | Best of (automatic offer, plan discount), never both; a manual coupon on top; a coupon can opt out with `combinable_with_offers = false` |
| Space-time offers (unchanged) | Priced by **usage time**: the seconds inside the offer's window at the offer's terms, the rest at the normal rate, rounded up once |
| Duration/volume saving | Defined per plan as **a reference × units** (the 12-month plan = monthly × 12; the 25-hour bundle = the hourly rate × 25). It is **not an offer**: it changes no price, does not count as "on offer" for a non-combinable coupon, and is reported in its own category |
| Percentages | **Rounded down** everywhere, so no discount or saving is overstated |
| Report | Every operation with a discount or saving in the period, **with its status**; cancelled, rejected, failed and reversed operations are listed but excluded from the totals |

## Defaults taken in this design (flagged for review)

| # | Question | Default |
|---|---|---|
| E1 | Fixed price ("the plan at 100 instead of 120") | A third discount type `fixed_price`, for **automatic offers only**. On time in a space it is an hourly rate; on a plan or event, the price |
| E2 | A booking spanning two offers' windows | One offer per purchase: the one giving the lower total; offers are not mixed segment by segment |
| E3 | Percentage or amount on time in a space | Applied to the in-window time: `percentage` takes p% off the hourly rate there; `amount_off` takes that many points off the hourly rate there (never below 0); `max_discount_points` caps the total. Exact arithmetic, rounded up once. On a plan or event, as a coupon: off the price |
| E4 | An automatic offer that cannot apply (audience, limit reached, not started) | Silently not applied; no rejection message. Rejections stay for typed codes |
| E5 | Who reads the report | `admin` and `operations`, like the coupon reads |
| E6 | Report rows | Operations carrying at least one discount or saving; totals over the non-cancelled ones |
| E7 | Saving reference price | The reference's **list** price, never its offer price |
| E8 | Displayed offer rate when a percentage leaves a fraction of a point per hour | Rounded **up** for display (never understates the price); the charge is exact |

## The model

### Offers on the coupon engine

`coupons` gains:

- `is_automatic` (bool, default false). An automatic coupon is an **offer**:
  it has **no code** (`code` becomes nullable; a manual coupon still requires
  one), and the pricing pipeline looks for it instead of waiting for it to be
  typed.
- `discount_type` gains `fixed_price` (offers only, E1). `discount_value` is
  then the price: points per hour for time in a space, points for a plan or
  event. A fixed price at or above the normal one is not a discount, and is
  ignored.

Everything else is shared with manual coupons: `targets` (none = every
purpose a coupon can be spent on, never a wallet top-up; or a purpose plus
one plan, space, space type or event), the audience (`user_ids`,
`company_id`, `new_members_only`), the limits (`usage_limit`,
`per_user_limit`), the window (`starts_at`, `ends_at`), `is_active`,
`max_discount_points`, `min_amount_points`, `name`, `description`.

A `fixed_price` offer needs at least one target, all of the same family:
time in a space (`booking`, `coworking_session`), plans, or events. A price
means nothing across families.

### Choosing the automatic offer

`OfferResolver` (new, in `App\Domain\Pricing\Services`) answers: for this
member and this target, over this time (or at this moment), which automatic
offer gives the lowest price, and at what price?

1. Candidates: active automatic coupons whose targets match the
   `PricingTarget` (the same matching as `AppliesToTarget`).
2. For each, eligibility through the existing rules (`MemberIsInAudience`,
   `IsForNewMembers`, `IsWithinUsageLimits`, `IsWithinValidity`). A refusal
   drops the candidate silently (E4). Validity for time in a space means the
   window overlaps the booked time; for a plan, live when the payment opens;
   for an event, live at registration.
3. Price each survivor:
   - time in a space: `SpaceRateTimeline` builds the segments with the
     offer's terms inside its window (E3), `AmountCalculator::forSegments`
     rounds once;
   - plan or event: the offer's terms on the price.
4. The lowest price wins; a tie goes to the offer ending first, then the
   lowest id (deterministic).

`SpaceRateTimeline` and `AmountCalculator::forSegments` keep their roles.
`RateSegment` takes an exact decimal rate (a bcmath string) instead of an
int, so 15 points/hour at 10% off is 13.5, not rounded per segment.

### The pricing algorithm (the phase-1 algorithm, with the offer in the sale's place)

```
L  list price                       AmountCalculator (time rounds up once)
O  best automatic offer price       OfferResolver (or L when none)
P  plan discount price              PlanDiscountService, bookings only
A  min(O, P)                        a tie takes the offer (it spends no plan-discount use)
manual coupon                       on A if combinable; else on L if lower than A (else offer_is_better)
```

`PriceQuote` keeps its shape. `PriceChoice::Sale` becomes `PriceChoice::Offer`
and the quote carries the chosen offer (`?Coupon $offer`). The breakdown line
key stays `sale` (the app already reads it); its label becomes the offer's
name, or "Offer" when it has none.

### Locked terms (extensions and closures)

The offer's terms are copied onto the record when the price is locked, like a
coupon's. An extension keeps the booking's choice (D7): the offer it was
priced with, at its copied terms and over its copied window, by usage time;
else its plan discount; else the list price. A walk-in or a booking left
unpaid and priced at check-out picks its offer at closure, over the time
actually used, and locks it then.

### Duration and volume savings

`plans` gains:

- `reference_plan_id` (nullable FK to `plans`, null on delete), **or**
- `reference_space_id` (nullable FK to `spaces`, null on delete): its
  hourly rate is the reference;
- `reference_units` (unsigned int, required when a reference is set).

Exactly one of the two references, or neither. A plan cannot reference
itself.

`PlanSaving` (new value object) and `PlanSavingCalculator` (new service):

```
reference_points = reference list price × units      (E7)
saving_points    = reference_points − plan.price_points   (none when ≤ 0)
saving_percent   = floor(saving_points × 100 / reference_points)
per_unit_points  = plan.price_points / units          (exact; for display)
```

A saving never enters the price. It is shown and it is recorded.

### The discount ledger

`coupon_redemptions` (phase 1, not in production) becomes **`applied_discounts`**:
one row per reduction applied to an operation, with a copy of where it came
from. It is the one place a report reads.

| Column | Meaning |
|---|---|
| `id`, `created_at`, `updated_at` | `created_at` = when the price was locked |
| `user_id`, `purpose` | as before |
| `booking_id`, `walkin_session_id`, `event_attendee_id`, `payment_id` | exactly one set (unchanged guard); **no longer unique alone** |
| `kind` | `offer` \| `plan_discount` \| `coupon` \| `duration_saving` |
| `coupon_id` | the offer or coupon (null for the other kinds) |
| `plan_discount_id` | the plan discount (null otherwise) |
| `discount_type`, `discount_value`, `max_discount_points` | terms copy (offer/coupon); `percentage` for a plan discount is `discount_type = percentage`, `discount_value = %` |
| `base_points` | the price the reduction applied to (for a saving: the reference price) |
| `points` | what it took off (for a saving: what the plan saves) — renamed from `points_discounted` |
| `percent` | `floor(points × 100 / base_points)` |
| `terms` (json) | the rest of the copy, for display: code, name, window (offer), reference name and units (saving) |

Unique per record and kind: `(booking_id, kind)`, `(walkin_session_id, kind)`,
`(event_attendee_id, kind)`, `(payment_id, kind)`. An operation therefore has
at most one offer, one plan discount, one coupon and one saving.

The live scope (`scopeLive`) is unchanged: whether a row counts follows the
record's status. Usage limits count live rows of kinds `offer` and `coupon`
for the coupon. `CouponLedger` reads by kind (`couponFor($record)`,
`offerFor($record)`); `QuoteRecorder` writes every kind, in the transaction
that saved the record, next to `PlanDiscountRedemption` (kept: it drives the
plan discount's own usage limit and the legacy refunds).

`CouponTerms::fromRedemption()` reads the copied terms for any kind.

### The report

`GET admin/reports/discounts` (admin and operations, E5), paginated.

Query: `from`, `to` (local dates, inclusive; matched against when the price
was locked, the ledger rows' `created_at`), `kind` (one of the four),
`purpose`, `status` (`counted` | `void` | all), `per_page`.

Each row is an operation (grouped ledger rows of one record):

```json
{
  "record": { "type": "booking", "id": 812, "reference": "BK-2026-00812" },
  "member": { "id": 31, "name": "…", "phone": "…" },
  "purpose": "booking",
  "at": "2026-10-06T10:00:00Z",
  "status": "confirmed",
  "counted": true,
  "list_points": 200,
  "final_points": 136,
  "discounts": [
    { "kind": "offer",  "name": "Meeting rooms week", "points": 40, "percent": 20 },
    { "kind": "coupon", "code": "WELCOME20", "points": 24, "percent": 15 }
  ],
  "saving": null
}
```

`counted` is the live scope: false for cancelled, rejected, failed, expired
or reversed operations. Totals (`meta.totals`), over counted rows only:
operation count, `list_points`, `final_points`, points per kind, and the
effective percent `floor((list − final) × 100 / list)`. Savings are totalled
separately and never subtracted from list.

### What the member sees

- **`sale` block** (spaces and packages; shape from `feat/sale-discount-labels`
  kept): now built from the best automatic offer live **now** for **this
  member** (audience and limits apply, so a members-only offer shows only to
  them). Packages are therefore priced with the member, not anonymously.
  `discount_label` and `ends_label` as before; a percentage that leaves a
  fraction of a point per hour shows the rate rounded up (E8).
- **`saving` block** (packages list and details; new):
  `{ compared_to, reference_points, reference_label, saving_points,
  saving_percent, saving_label, per_unit_label }`, null when the plan has no
  reference or no saving. It sits beside `sale`; both can show at once.
- Breakdown lines: `sale` (offer, labelled with its name), `discount` (plan),
  `coupon` — unchanged keys.

### What the admin sees and does

- **Offers** are coupons with `is_automatic: true` on the existing
  `admin/coupons` endpoints. `GET admin/coupons?is_automatic=1|0` filters;
  the resource returns `is_automatic`. Creating an offer sends no `code`;
  creating a coupon still requires one. Deleting a used offer answers 409, as
  for coupons: its uses keep their copied terms, so history survives an edit
  or a deactivation.
- **Sale fields leave the space and plan forms.** `StoreSpaceRequest` /
  `StorePlanRequest` stop accepting `sale_*`; the resources stop returning
  them; `ValidatesSaleWindow`, `HasSalePrice` and `SaleWindow`-on-models go.
  (`SaleWindow` stays as the window value object the offers use.)
- **Plan form** gains `reference_plan_id` / `reference_space_id` /
  `reference_units`, validated as above; `PlanResource` returns them and the
  computed `saving` on admin routes.
- **Report**: `GET admin/reports/discounts`.

## Schema changes (one migration per concern)

1. Drop `sale_hourly_rate_points`, `sale_starts_at`, `sale_ends_at` from
   `spaces`; `sale_price_points`, `sale_starts_at`, `sale_ends_at` from `plans`.
2. `coupons`: `code` nullable; add `is_automatic` (bool, default false, indexed).
3. Rename `coupon_redemptions` → `applied_discounts`; add `kind` (string 20,
   default `coupon` for existing rows), `plan_discount_id` (nullable FK, null
   on delete), `percent` (unsigned tinyint), `terms` (json, nullable); make
   `coupon_id` nullable; rename `points_discounted` → `points`; replace the
   single-column unique indexes with the four `(record, kind)` ones.
4. `plans`: `reference_plan_id`, `reference_space_id`, `reference_units`.
5. Backfill: `percent` for existing rows; a `plan_discount` ledger row for each
   existing `plan_discount_redemptions` row (percentage from its plan
   discount as it is now, the best record left).

## Special cases

- **An automatic offer whose limit runs out mid-batch** (multi-date booking):
  `UsageInFlight` counts it like a coupon; later dates price without it.
- **An offer edited after use**: past operations keep their copied terms; the
  report shows what applied then.
- **A plan with a reference and a live offer**: the `saving` compares list
  prices; the `sale` block shows the offer. The ledger records both: the offer
  (a reduction) and the saving (informational).
- **A non-combinable coupon on a plan with a saving**: allowed; a saving is
  not an offer.
- **Reception** still takes no manual coupon (phase-1 D5), but automatic offers
  apply there like anywhere else: the pipeline finds them.

## Testing

- Unit: `OfferResolver` choice (best, tie, ineligible dropped), `RateSegment`
  decimal rates, `PlanSavingCalculator`, percent floors.
- Feature: offers on bookings (usage time, two overlapping offers, audience),
  on plans and events; offers with a coupon on top (combinable and not);
  extension and closure at locked terms; ledger rows per kind; live counting;
  the report (filters, statuses, totals exclude void, savings apart); admin
  offer CRUD (no code, `fixed_price` families); plan references validation;
  member `sale` (per member) and `saving` blocks.
- The phase-1 sale tests are rewritten onto offers; `AdminSaleFieldsTest` is
  replaced by offer CRUD tests.
- Guards: `PricingIsCentralizedTest` gains: `applied_discounts` is written only
  by `CouponLedger`/`QuoteRecorder`.

## Out of scope

- Admin-set priorities between offers (best-for-member only).
- Showing upcoming offers before they start.
- CSV/Excel export of the report (the endpoint is the base for it).
- Manual coupons at reception and on company purchases (phase-1 D5).
- Gift codes and referrals (phases 2 and 3).

## Rollout notes

- The dashboard must not build sale fields on the space and plan forms: offers
  get their own screen on `admin/coupons?is_automatic=1`.
- The member app keeps reading `sale` (shape unchanged) and adds `saving`.
- Postman, the mobile guide (section 12) and the decision doc are updated with
  the code.
