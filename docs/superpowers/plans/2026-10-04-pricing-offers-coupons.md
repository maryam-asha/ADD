# Plan: Sale Prices, Coupons and One Pricing Pipeline (Pricing Phase 1)

**Spec:** `docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md`
**Branch:** `feat/pricing-offers-coupons` — from `feat/add-point-system`
(a67b1bb) with `fix/unified-payments-review` merged in.
**Execution:** inline, TDD, one commit per task.

## Global constraints

- Integer points, bcmath only; time prices ceil once, discounts floor
  (`PointRoundingFavoursTheHouseTest`, `MoneyIsDecimalOnlyTest`).
- Every new string-backed enum cast is registered in
  `EnumColumnsHaveBackedEnumCastsTest`.
- `Pricing` joins Booking/Access/Membership/Finance on
  `DomainLayerBoundaryTest`'s forbidden lists for Experience/Ecosystem.
- Lang keys in both `lang/en/api.php` and `lang/ar/api.php` (watch for a
  duplicated top-level group after a merge — the last one silently wins).
- Update endpoints answer `{message}`; `store()` answers the resource.
- Migrations dated `2026_10_05_1000xx` onward.
- The coupon row is the first lock in any transaction that redeems it.

## Tasks

### Task 0: Plan discounts are reconciled, never wiped
`Admin\PlanController::update()` reconciles `discounts` by `id`;
`UpdatePlanRequest` accepts `discounts.*.id` scoped to the plan; a discount
left out is deleted unless it has redemptions, then deactivated.
Tests: `PlanCatalogTest` (three new cases).

### Task 1: Spec and plan documents
This file and the spec.

### Task 2: Pricing enums
`app/Domain/Pricing/Enums/{PricingPurpose, CouponDiscountType, CouponSource,
CouponRejection}`; `PaymentStatus::holdsRedemption()`; `Pricing` in
`DomainLayerBoundaryTest`. Tests: `tests/Unit/Domain/Pricing/*EnumTest`.

### Task 3: Space sale columns
Migration; `Space` fillable/casts; `Pricing/ValueObjects/SaleWindow
{isLiveAt, clip}`; `Pricing/Concerns/HasSalePrice`. Test: `SaleWindowTest`.

### Task 4: Plan sale columns
Migration; `Plan` fillable/casts, same trait.

### Task 5: Segment pricing
`AmountCalculator::forSegments(list<RateSegment>)`; `forRange` delegates.
Test: `AmountCalculatorSegmentsTest`.

### Task 6: Space rate timeline
`Pricing/Services/SpaceRateTimeline::segments(Space, from, to)`.
Test: `SpaceRateTimelineTest`.

### Task 7: Coupon tables and models
Migrations for `coupons`, `coupon_targets`, `coupon_user`,
`coupon_redemptions`; models with guards and `scopeLive`; factories.
Test: `CouponModelTest`.

### Task 8: `list_price_points`
Migration on bookings / walkin_sessions / event_attendees / payments +
backfill. Test: `BackfillListPricePointsMigrationTest`.

### Task 9: Discount arithmetic
`CouponTerms`, `CouponCalculator::discountFor()`. Test: `CouponCalculatorTest`.

### Task 10: New-member policy
`FirstPurchasePolicy::isFirstPurchase(User)` (spec D4).
Test: `FirstPurchasePolicyTest`.

### Task 11: Coupon ledger
`CouponLedger::{lockForRedemption, liveUsage, record, forRedeemable}`.
Test: `CouponLiveUsageTest`.

### Task 12: Coupon eligibility
`CouponEligibility::{resolve, assertUsable}`, rule objects,
`CouponRejectedException`, `PricingTarget`, `UsageInFlight`.
Test: `CouponEligibilityTest` (one case per rejection).

### Task 13: Booking quote
`PriceQuote`, `PriceLine`, `PriceQuoteService::forBooking()`.
Test: `PriceQuoteBookingTest` (the algorithm's matrix).

### Task 14: Booking creation
`BookingCreationService::create(..., ?string $couponCode)`,
`StoreBookingRequest`, no card start at 0. Tests:
`BookingCouponCreationTest`, `BookingSaleCreationTest`.

### Task 15: Batch creation
`UsageInFlight` replaces the `consumedInBatch` array; `coupon_code` on the
batch request. Test: in `BookingCouponCreationTest`.

### Task 16: Preview and breakdown
`forExistingBooking()`; `breakdown()` from a `PriceQuote`; `coupon_code` on
the preview request. Test: `BookingPreviewCouponTest`.

### Task 17: Extension
`forExtension()`; `BookingExtensionPlanner::price()` delegates.
Test: `BookingExtensionPricingTest`.

### Task 18: Closure
`forClosure()` in `SessionClosureService`; `SessionReportBuilder` reads
stored prices. Test: `SessionClosurePricingTest`.

### Task 19: Plan quote
`forPlan()`, `forPlanPayment()`; `PlanPurchaseFulfiller`,
`OutstandingItems::heldPlans`. Test: `PlanSalePriceTest`.

### Task 20: Plan coupons
`PaymentService::start()` ordering and free path; `StartPayment`;
`MembershipController` / `PaymentController` requests.
Test: `PlanCouponPurchaseTest`.

### Task 21: Coupon on an existing payable
`Pricing/Services/CouponApplication::apply()`. Test: `CouponApplicationTest`.

### Task 22: Event registration
`EventRegistrationService::register(..., ?string $couponCode)`; 0 = Paid.
Test: `EventRegistrationCouponTest`.

### Task 23: Member coupon preview
`POST member/coupons/preview`; `coupon_code` on `payment-methods`.
Test: `CouponPreviewControllerTest`.

### Task 24: Member sale labels
`PriceLabelPresenter::sale()`; `sale` block on space and package browse.
Tests: browse/package tests.

### Task 25: Admin sale fields
`StoreSpaceRequest` / `StorePlanRequest` / resources.
Tests: `SpaceControllerTest`, `PlanCatalogTest`.

### Task 26: Admin coupons CRUD
`CouponController`, requests, resources, redemptions endpoint, audit.
Test: `tests/Feature/Admin/CouponControllerTest`, permission sync.

### Task 27: Guard
`tests/Guards/PricingIsCentralizedTest`.

### Task 28: Translations sweep
`api.coupon.*` and labels in en/ar.

### Task 29: Postman and decision docs
Postman folders; `docs/decisions/pricing-offers-and-coupons.md` + README;
updates to `plan-discounts-design.md`, `unified-payments.md`,
`booking-outcomes-and-extension.md`, mobile API doc.

## Review focus

- Concurrency of `usage_limit` / `per_user_limit` (lock order; SQLite cannot
  prove it).
- Rounding: one ceil over all segments; a discount never exceeds its base.
- A coupon's use is given back on every exit path (live scope), and never
  twice-counted across a batch.
