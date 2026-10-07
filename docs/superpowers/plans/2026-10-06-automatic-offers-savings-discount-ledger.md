# Automatic Offers, Duration Savings and the Discount Ledger Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the per-item sale columns with automatic offers on the coupon engine, add per-plan duration/volume savings, turn `coupon_redemptions` into an `applied_discounts` ledger of every reduction, and serve a discount report.

**Architecture:** An offer is a `Coupon` with `is_automatic = true` and no code. A new `OfferResolver` picks the best eligible offer for a member and target; `PriceQuoteService` uses it where it used the sale (`PriceChoice::Offer`). `QuoteRecorder` writes one `applied_discounts` row per kind (`offer`, `plan_discount`, `coupon`, `duration_saving`) with copied terms; locked prices (extension, closure, plan payment, existing booking) read those rows back. Savings are a display/record-only `PlanSaving` computed from a plan's reference plan or space.

**Tech Stack:** Laravel 12, PHP 8.x bcmath, Eloquent, PHPUnit (in-memory SQLite in tests, MySQL in prod).

**Spec:** `docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md`
**Branch:** `feat/pricing-offers-engine` (this worktree), on top of `d8eba17`.

## Global Constraints

- Integer points; bcmath only inside `app/Domain/Pricing` (`PricingIsCentralizedTest::test_pricing_arithmetic_is_integer_bcmath` bans `ceil/floor/round/(float)` there).
- Time prices round **up once** over all segments; discounts and percentages round **down** (spec "Percentages: Rounded down everywhere").
- A displayed offer hourly rate with a fraction of a point is rounded **up** (E8); the charge is exact.
- Every new string-backed enum cast is registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`.
- Lang keys in both `lang/en/api.php` and `lang/ar/api.php`; check no top-level group is duplicated (memory: the last one silently wins).
- Update endpoints answer `{message}`; `store()` answers the resource.
- Migrations dated `2026_10_06_1000xx`, one per concern (spec "Schema changes").
- Locking protocol (`CouponLedger` docblock): the coupon/offer rows are the **first** lock and the **first read** in any transaction that may spend an offer or coupon; order Coupon(s) by id → Booking → Branch → Space / Event → Payment → Wallet.
- Suite baseline: 17 failures predate this work (memory "Pre-existing SQLite audit-log test failures"); diff against the baseline, never assume a red test is new.
- Never read `vendor/`.

## Review Focus

1. **No member** (anonymous package browse, staff-side preview with `$member = null`): an offer with any audience (`user_ids`, `company_id`, `new_members_only`) must neither show nor apply; an offer for everyone still does (usage limit counted, per-user limit skipped). Tested in Task 4 and Task 7.
2. **Open-ended offers** (`starts_at` and/or `ends_at` null): price over the whole booking; the `sale` block shows `ends_at: null`, `ends_label: null` instead of crashing. Tested in Task 4 and Task 7.
3. **A 100% offer makes a plan free:** `MembershipController` / `PaymentController` decide "free" only when a coupon was sent; an automatic offer reaching 0 must take the same free path. Tested in Task 5.
4. **Offer limit reached mid-batch:** a `usage_limit: 1` offer on a two-date batch prices the first date with it and the second without. Tested in Task 5.
5. **Offer edited or switched off after a booking locked it:** extension and check-out still price at the copied terms and window. Tested in Task 6.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_10_06_100000_add_automatic_offers_to_coupons_table.php` | `code` nullable, `is_automatic` |
| `database/migrations/2026_10_06_100100_turn_coupon_redemptions_into_applied_discounts.php` | rename + new columns + `(record, kind)` uniques |
| `database/migrations/2026_10_06_100200_backfill_applied_discounts.php` | `percent`, `plan_discount` rows |
| `database/migrations/2026_10_06_100300_drop_sale_columns.php` | spaces/plans sale columns gone |
| `database/migrations/2026_10_06_100400_add_saving_reference_to_plans_table.php` | `reference_plan_id`, `reference_space_id`, `reference_units` |
| `app/Domain/Pricing/Enums/DiscountKind.php` | the four ledger kinds |
| `app/Domain/Pricing/Enums/CouponDiscountType.php` | + `FixedPrice` |
| `app/Domain/Pricing/Enums/PriceChoice.php` | `Sale` → `Offer` |
| `app/Domain/Pricing/Models/AppliedDiscount.php` | replaces `CouponRedemption` |
| `app/Domain/Pricing/ValueObjects/SaleWindow.php` | open-bounded window, no price |
| `app/Domain/Pricing/ValueObjects/CouponTerms.php` | + `?SaleWindow $window`, `fromLedger()` |
| `app/Domain/Pricing/ValueObjects/OfferPrice.php` | an offer and the price it gives |
| `app/Domain/Pricing/ValueObjects/PlanSaving.php` | a plan's built-in saving |
| `app/Domain/Pricing/Services/OfferResolver.php` | best automatic offer |
| `app/Domain/Pricing/Services/PlanSavingCalculator.php` | saving from reference × units |
| `app/Domain/Pricing/Services/DiscountReport.php` | report rows and totals |
| `app/Http/Controllers/Api/V1/Admin/DiscountReportController.php` + `app/Http/Requests/Admin/DiscountReportRequest.php` + `app/Http/Resources/DiscountReportRowResource.php` | `GET admin/reports/discounts` |
| deleted: `app/Domain/Pricing/Concerns/HasSalePrice.php`, `app/Http/Requests/Concerns/ValidatesSaleWindow.php`, `app/Domain/Pricing/Models/CouponRedemption.php` | |

---

### Task 0: Baseline

- [ ] **Step 1:** Run `php artisan test 2>&1 | tail -40` in the worktree and save the list of failing test names to the scratchpad as `baseline-failures.txt`. Every later "run the suite" step diffs against it.

---

### Task 1: Offers on the coupon schema

**Files:**
- Create: `database/migrations/2026_10_06_100000_add_automatic_offers_to_coupons_table.php`
- Modify: `app/Domain/Pricing/Enums/CouponDiscountType.php`, `app/Domain/Pricing/Models/Coupon.php`, `database/factories/CouponFactory.php`, `app/Domain/Pricing/Services/CouponEligibility.php`, `app/Domain/Pricing/Services/CouponCalculator.php`
- Test: `tests/Feature/Pricing/CouponModelTest.php`, `tests/Unit/Domain/Pricing/CouponCalculatorTest.php`

**Interfaces:**
- Produces: `Coupon::$is_automatic` (bool), `Coupon::scopeAutomatic(Builder)`, `Coupon::window(): SaleWindow` (added in Task 3 — leave out here), `CouponDiscountType::FixedPrice`, `CouponFactory::automatic()`, `CouponFactory::fixedPrice(int)`, `CouponCalculator::discountFor(CouponTerms, int $base): int` handling `FixedPrice` as `base − value` (0 when value ≥ base).

- [ ] **Step 1: Write the failing tests**

In `CouponModelTest`:
```php
public function test_an_offer_has_no_code(): void
{
    $offer = Coupon::factory()->automatic()->percentage(20)->create();

    $this->assertNull($offer->fresh()->code);
    $this->assertTrue($offer->fresh()->is_automatic);
}

public function test_two_offers_without_codes_coexist(): void
{
    Coupon::factory()->automatic()->count(2)->create();

    $this->assertSame(2, Coupon::query()->automatic()->count());
}

public function test_a_typed_code_never_resolves_to_an_offer(): void
{
    $this->expectException(CouponRejectedException::class);

    app(CouponEligibility::class)->resolve('');
}
```
In `CouponCalculatorTest`:
```php
public function test_a_fixed_price_takes_off_the_difference(): void
{
    $this->assertSame(20, (new CouponCalculator)->discountFor(new CouponTerms(CouponDiscountType::FixedPrice, 100), 120));
}

public function test_a_fixed_price_at_or_above_the_price_takes_nothing(): void
{
    $this->assertSame(0, (new CouponCalculator)->discountFor(new CouponTerms(CouponDiscountType::FixedPrice, 120), 120));
    $this->assertSame(0, (new CouponCalculator)->discountFor(new CouponTerms(CouponDiscountType::FixedPrice, 150), 120));
}

public function test_a_fixed_price_is_capped_by_max_discount_points(): void
{
    $this->assertSame(10, (new CouponCalculator)->discountFor(new CouponTerms(CouponDiscountType::FixedPrice, 50, 10), 120));
}
```

- [ ] **Step 2:** Run `php artisan test tests/Feature/Pricing/CouponModelTest.php tests/Unit/Domain/Pricing/CouponCalculatorTest.php` — expect FAIL (`automatic()` undefined, `FixedPrice` undefined).

- [ ] **Step 3: Implement**

Migration:
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->change();
            $table->boolean('is_automatic')->default(false)->after('code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropIndex(['is_automatic']);
            $table->dropColumn('is_automatic');
            $table->string('code', 40)->nullable(false)->change();
        });
    }
};
```
Docblock: an offer is a coupon with no code that applies itself (spec "Offers on the coupon engine"); a unique index allows many NULLs on MySQL and SQLite.

`CouponDiscountType`: add `case FixedPrice = 'fixed_price';` and extend the docblock: offers only; `discount_value` is the price (points per hour for time in a space, points for a plan or event).

`Coupon`: add `'is_automatic'` to `$fillable` and `'is_automatic' => 'boolean'` to casts; `setCodeAttribute(?string $value)` stores `null` for null; add
```php
public function scopeAutomatic(Builder $query): Builder
{
    return $query->where('is_automatic', true);
}
```
The `booted()` guard keeps `value ≥ 1` for every type and `≤ 100` for percentage; add: a `FixedPrice` coupon that is not automatic throws `InvalidArgumentException('A fixed price is for automatic offers only.')`. Update the class docblock: "A code a member types, or — `is_automatic` — an offer that applies itself."

`CouponFactory`: `automatic()` sets `['is_automatic' => true, 'code' => null, 'per_user_limit' => null]`; `fixedPrice(int $points)` sets `discount_type` FixedPrice + `discount_value`.

`CouponEligibility::resolve()`: add `->where('is_automatic', false)`.

`CouponCalculator::discountFor()` match arm: `CouponDiscountType::FixedPrice => max(0, $base - $terms->value),`.

- [ ] **Step 4:** Re-run the two files — PASS.
- [ ] **Step 5:** Commit `feat(pricing): an offer is a coupon with no code`.

---

### Task 2: Exact decimal rates in segments

**Files:**
- Modify: `app/Domain/Booking/ValueObjects/RateSegment.php`, `app/Domain/Booking/Services/AmountCalculator.php`
- Test: `tests/Unit/Domain/Booking/AmountCalculatorSegmentsTest.php` (exists from phase 1; add cases)

**Interfaces:**
- Produces: `new RateSegment(int $seconds, int|string $rate)` with `public readonly string $rate` (a bcmath numeric string, scale ≤ 2). `ratePoints` is removed — grep and replace every reader.

- [ ] **Step 1: Write the failing test**
```php
public function test_a_fractional_rate_is_summed_exactly_and_rounded_once(): void
{
    $calculator = new AmountCalculator;

    // 2 h at 13.5/h is exactly 27 — rounding the rate first would say 28.
    $this->assertSame(27, $calculator->forSegments([new RateSegment(7200, '13.5')]));
    // 1 h at 13.5/h is 13.5, charged 14.
    $this->assertSame(14, $calculator->forSegments([new RateSegment(3600, '13.5')]));
    // 30 min at 13.5 + 30 min at 15 = 14.25, charged 15.
    $this->assertSame(15, $calculator->forSegments([new RateSegment(1800, '13.5'), new RateSegment(1800, 15)]));
}
```
- [ ] **Step 2:** Run it — FAIL (TypeError: string given).
- [ ] **Step 3: Implement**

`RateSegment`:
```php
final class RateSegment
{
    /** The hourly rate in points, exact: an offer's 10% off 15 is 13.5. */
    public readonly string $rate;

    public function __construct(
        public readonly int $seconds,
        int|string $rate,
    ) {
        $this->rate = (string) $rate;
    }
}
```
`AmountCalculator::forSegments()`:
```php
$points = '0';

foreach ($segments as $segment) {
    if ($segment->seconds <= 0 || bccomp($segment->rate, '0', 2) <= 0) {
        continue;
    }

    $points = bcadd($points, bcmul((string) $segment->seconds, $segment->rate, 2), 2);
}

// Seconds × rate is point-seconds; divide by 3600 once and round up once.
$whole = bcdiv($points, (string) self::SECONDS_PER_HOUR, 0);

return (int) (bccomp($points, bcmul($whole, (string) self::SECONDS_PER_HOUR, 2), 2) > 0 ? bcadd($whole, '1') : $whole);
```
Update the docblock: rates may be exact decimals (an offer's percentage); still one rounding.

- [ ] **Step 4:** Run `php artisan test tests/Unit/Domain/Booking tests/Unit/Domain/Pricing/SpaceRateTimelineTest.php` — PASS (fix `SpaceRateTimelineTest` reads of `ratePoints` → `rate`, asserting strings, e.g. `'80'`).
- [ ] **Step 5:** Commit `feat(pricing): a segment's rate is exact`.

---

### Task 3: The `applied_discounts` ledger

**Files:**
- Create: `database/migrations/2026_10_06_100100_turn_coupon_redemptions_into_applied_discounts.php`, `database/migrations/2026_10_06_100200_backfill_applied_discounts.php`, `app/Domain/Pricing/Enums/DiscountKind.php`, `app/Domain/Pricing/Models/AppliedDiscount.php`
- Delete: `app/Domain/Pricing/Models/CouponRedemption.php`
- Modify: `app/Domain/Pricing/Models/Coupon.php` (`redemptions()` → `AppliedDiscount`), `app/Domain/Pricing/Services/CouponLedger.php`, `app/Domain/Pricing/Services/QuoteRecorder.php`, `app/Domain/Pricing/Services/PriceQuoteService.php` (type hints, `points`), `app/Domain/Pricing/Services/FirstPurchasePolicy.php`, `app/Domain/Pricing/ValueObjects/CouponTerms.php`, `app/Http/Controllers/Api/V1/Admin/CouponController.php`, `app/Http/Resources/CouponRedemptionResource.php`, `app/Support/Presenters/CouponOutcomePresenter.php`, `app/Domain/Finance/Payments/PaymentService.php`, `app/Domain/Finance/Services/StartPayment.php`, `app/Domain/Pricing/Services/CouponApplication.php`, `app/Http/Requests/Admin/UpdateCouponRequest.php` (docblock), `app/Http/Resources/CouponResource.php` (docblock), `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Feature/Pricing/CouponLedgerTest.php`, new `tests/Feature/Pricing/AppliedDiscountsMigrationTest.php`

**Interfaces:**
- Produces:
  - `enum DiscountKind: string { Offer='offer'; PlanDiscount='plan_discount'; Coupon='coupon'; DurationSaving='duration_saving'; }` with `public static function reductions(): array` returning `[Offer, PlanDiscount, Coupon]`.
  - `AppliedDiscount` (table `applied_discounts`), fillable: `user_id, purpose, booking_id, walkin_session_id, event_attendee_id, payment_id, kind, coupon_id, plan_discount_id, discount_type, discount_value, max_discount_points, base_points, points, percent, terms`; casts `kind` → `DiscountKind`, `purpose` → `PricingPurpose`, `discount_type` → `CouponDiscountType`, `terms` → `array`, ints for the rest; `RECORD_COLUMNS` const; `scopeLive()` (moved unchanged); relations `coupon, planDiscount, user, booking, walkinSession, eventAttendee, payment`; `static percentOf(int $points, int $base): int` = `$base > 0 ? intdiv($points * 100, $base) : 0`.
  - `CouponLedger::recordColumn(Model $record): string` (public, was private `column`), `CouponLedger::row(Model $record, DiscountKind $kind): ?AppliedDiscount`, `couponFor(Model)`, `offerFor(Model)`, `planDiscountFor(Model)`, `rowsFor(Model): Collection<AppliedDiscount>`, `write(Model $record, User $member, PricingPurpose $purpose, DiscountKind $kind, array $attributes): AppliedDiscount` (updateOrCreate on `(record column, kind)`, fills `percent` from `base_points`/`points`). `record(...)` and `forRedeemable(...)` are removed; callers use `QuoteRecorder` / `couponFor()`.
  - `CouponTerms::fromLedger(AppliedDiscount $row): self` (replaces `fromRedemption`).
  - `QuoteRecorder::record(PriceQuote $quote, Model $record, User $member, PricingPurpose $purpose): void` writes a `plan_discount` row next to `PlanDiscountRedemption` and a `coupon` row (offer and saving rows come in Tasks 5 and 8).

- [ ] **Step 1: Write the failing tests**

`AppliedDiscountsMigrationTest` (pattern of `BackfillListPricePointsMigrationTest`: roll back to before `2026_10_06_100100`, seed, migrate):
```php
public function test_existing_coupon_uses_become_coupon_rows_with_a_percent(): void
{
    // seed a coupon_redemptions row: base 200, points_discounted 30
    // migrate; assert applied_discounts row kind 'coupon', points 30, percent 15
}

public function test_each_plan_discount_redemption_gets_a_plan_discount_row(): void
{
    // seed a plan_discount (25%) and a plan_discount_redemptions row on a booking with list_price_points 200, points_discounted 50
    // migrate; assert one applied_discounts row: kind plan_discount, plan_discount_id set, discount_type 'percentage', discount_value 25, base_points 200, points 50, percent 25
}

public function test_a_record_takes_one_row_per_kind(): void
{
    // after migrate: inserting two 'coupon' rows on one booking throws UniqueConstraintViolationException;
    // a 'coupon' and an 'offer' row on one booking are both accepted
}
```
`CouponLedgerTest` — rename assertions to `AppliedDiscount`/`points`, and add:
```php
public function test_a_booking_priced_with_a_plan_discount_and_a_coupon_files_both_kinds(): void
{
    // book with an active plan discount 20% and a combinable 10% coupon
    $rows = app(CouponLedger::class)->rowsFor($booking)->keyBy(fn ($r) => $r->kind->value);

    $this->assertSame(['coupon', 'plan_discount'], $rows->keys()->sort()->values()->all());
    $this->assertSame(20, $rows['plan_discount']->percent);
}
```

- [ ] **Step 2:** Run both — FAIL (table missing).

- [ ] **Step 3: Implement**

Migration `100100`:
```php
public function up(): void
{
    Schema::rename('coupon_redemptions', 'applied_discounts');

    Schema::table('applied_discounts', function (Blueprint $table) {
        $table->dropUnique('coupon_redemptions_booking_id_unique');
        $table->dropUnique('coupon_redemptions_walkin_session_id_unique');
        $table->dropUnique('coupon_redemptions_event_attendee_id_unique');
        $table->dropUnique('coupon_redemptions_payment_id_unique');
    });

    Schema::table('applied_discounts', function (Blueprint $table) {
        $table->string('kind', 20)->default('coupon')->after('purpose');
        $table->foreignId('plan_discount_id')->nullable()->after('coupon_id')->constrained('plan_discounts')->nullOnDelete();
        $table->unsignedTinyInteger('percent')->default(0)->after('points_discounted');
        $table->json('terms')->nullable()->after('percent');
        $table->foreignId('coupon_id')->nullable()->change();
        $table->string('discount_type', 20)->nullable()->change();
        $table->unsignedInteger('discount_value')->nullable()->change();
        $table->renameColumn('points_discounted', 'points');

        $table->unique(['booking_id', 'kind']);
        $table->unique(['walkin_session_id', 'kind']);
        $table->unique(['event_attendee_id', 'kind']);
        $table->unique(['payment_id', 'kind']);
        $table->index(['kind', 'created_at']);
    });
}
```
`down()` reverses it (drop new uniques/columns, rename back, re-add single uniques). Check the actual index names with `php artisan db:show`-free approach: the phase-1 migration used `->unique()` on the column, so Laravel named them `coupon_redemptions_<col>_unique`; keep the `dropUnique` by name. If SQLite rejects dropping the FK-backed unique in one `Schema::table`, split as above (already split).

Migration `100200` (backfill, one-way, `down()` empty with a comment):
```php
DB::table('applied_discounts')->where('base_points', '>', 0)->orderBy('id')->each(function ($row) {
    DB::table('applied_discounts')->where('id', $row->id)->update(['percent' => intdiv($row->points * 100, $row->base_points)]);
});

DB::table('plan_discount_redemptions')
    ->join('plan_discounts', 'plan_discounts.id', '=', 'plan_discount_redemptions.plan_discount_id')
    ->join('bookings', 'bookings.id', '=', 'plan_discount_redemptions.booking_id')
    ->select('plan_discount_redemptions.*', 'plan_discounts.percentage', 'bookings.user_id', 'bookings.list_price_points', 'bookings.price_points')
    ->orderBy('plan_discount_redemptions.id')
    ->each(function ($row) {
        $base = (int) ($row->list_price_points ?? ($row->price_points + $row->points_discounted));
        DB::table('applied_discounts')->insertOrIgnore([
            'user_id' => $row->user_id, 'purpose' => 'booking', 'booking_id' => $row->booking_id,
            'kind' => 'plan_discount', 'plan_discount_id' => $row->plan_discount_id,
            'discount_type' => 'percentage', 'discount_value' => $row->percentage,
            'base_points' => $base, 'points' => $row->points_discounted,
            'percent' => $base > 0 ? intdiv($row->points_discounted * 100, $base) : 0,
            'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
        ]);
    });
```
Docblock: the percentage is the plan discount's as it is now — the best record left (spec "Schema changes" 5).

`AppliedDiscount::booted()` keeps the exactly-one-record guard; adds: `coupon_id` required for `Offer`/`Coupon`, `plan_discount_id` required for `PlanDiscount`.

`QuoteRecorder::record()`:
```php
if ($discount !== null && $record instanceof Booking && ($membership = $this->memberships->activeFor($member)) !== null) {
    $this->planDiscounts->redeem($discount, $membership, $record, $quote->automaticPoints);
    $this->ledger->write($record, $member, $purpose, DiscountKind::PlanDiscount, [
        'plan_discount_id' => $discount->id,
        'discount_type' => CouponDiscountType::Percentage,
        'discount_value' => $discount->percentage,
        'base_points' => $quote->listPoints,
        'points' => $quote->automaticPoints,
    ]);
}

if (($coupon = $quote->appliedCoupon()) !== null) {
    $this->ledger->write($record, $member, $purpose, DiscountKind::Coupon, [
        'coupon_id' => $coupon->id,
        'discount_type' => $coupon->discount_type,
        'discount_value' => $coupon->discount_value,
        'max_discount_points' => $coupon->max_discount_points,
        'base_points' => $quote->couponBasePoints,
        'points' => $quote->couponPoints,
        'terms' => ['code' => $coupon->code, 'name' => $coupon->name],
    ]);
}
```
`PaymentService::start()` and `StartPayment::handle()`: replace `$this->couponLedger->record(...)` with `$this->recorder->record($quote, $payment, $payer, PricingPurpose::PlanPurchase)` (inject `QuoteRecorder`). `CouponApplication::apply()`: replace with `$this->ledger->write($locked, $member, $purpose, DiscountKind::Coupon, [...])` using the same attribute array (extract `QuoteRecorder::couponAttributes(Coupon, int $base, int $points): array` as a public static and call it from both).

Every `forRedeemable(` call → `couponFor(` (BookingController:276, MembershipController:219, PaymentController:188, CouponApplication:93, PriceQuoteService). `CouponOutcomePresenter::present(string, ?AppliedDiscount, ...)` reads `points`. `CouponRedemptionResource` returns `kind` and both `points` and `points_discounted` (same value; the dashboard may read the old name — remove in a later release). `CouponController::redemptions()` uses `AppliedDiscount`. `FirstPurchasePolicy::hasUsedAWelcome()` queries `AppliedDiscount`. Register `AppliedDiscount::kind` in `EnumColumnsHaveBackedEnumCastsTest`.

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing tests/Feature/Membership tests/Guards` — PASS (no behaviour changed besides the extra rows).
- [ ] **Step 5:** Commit `feat(pricing): one ledger for every reduction applied`.

---

### Task 4: OfferResolver and offer-aware time pricing

**Files:**
- Create: `app/Domain/Pricing/Services/OfferResolver.php`, `app/Domain/Pricing/ValueObjects/OfferPrice.php`
- Modify: `app/Domain/Pricing/ValueObjects/SaleWindow.php`, `app/Domain/Pricing/ValueObjects/CouponTerms.php`, `app/Domain/Pricing/Services/SpaceRateTimeline.php`, `app/Domain/Pricing/Models/Coupon.php`
- Test: `tests/Unit/Domain/Pricing/SaleWindowTest.php` (rewrite), `tests/Unit/Domain/Pricing/SpaceRateTimelineTest.php` (rewrite), new `tests/Feature/Pricing/OfferResolverTest.php`

**Interfaces:**
- Consumes: `CouponCalculator::discountFor` with `FixedPrice` (Task 1), decimal `RateSegment` (Task 2), `CouponLedger::liveUsage` (unchanged).
- Produces:
  - `SaleWindow::between(?CarbonInterface $startsAt, ?CarbonInterface $endsAt): self` with `public readonly ?CarbonImmutable $startsAt, $endsAt` (null = open), `isLiveAt()`, `clip()`, `overlaps(from, to): bool`. `SaleWindow::of()` and `$points` are removed in Task 5 (still used by `HasSalePrice` until then — keep `of()` as a thin wrapper this task only).
  - `Coupon::window(): SaleWindow`.
  - `CouponTerms` gains `public readonly ?SaleWindow $window = null`; `fromCoupon` sets it from the coupon; `fromLedger` from `terms['starts_at'|'ends_at']` (ISO strings or null).
  - `SpaceRateTimeline::segments(Space $space, CarbonInterface $from, CarbonInterface $to, ?CouponTerms $offer = null): list<RateSegment>` and `SpaceRateTimeline::offerRate(int $rate, CouponTerms $offer): ?string` (null when the offer gives no lower rate).
  - `final class OfferPrice { __construct(public readonly Coupon $offer, public readonly int $points, public readonly ?CarbonImmutable $endsAt) }`.
  - `OfferResolver`:
    - `forSpace(Space $space, CarbonInterface $from, CarbonInterface $to, ?User $member, PricingPurpose $purpose, UsageInFlight $inFlight): ?OfferPrice` — whole-range price.
    - `forItem(int $list, PricingTarget $target, ?User $member, CarbonInterface $at, UsageInFlight $inFlight): ?OfferPrice` — plan or event.
    - `priceSpace(Space $space, CarbonInterface $from, CarbonInterface $to, CouponTerms $terms): int` — at given terms (also used for locked terms), capped by `max_discount_points`.
    - `priceItem(int $list, CouponTerms $terms): int`.
    - `hourlyNow(Space $space, ?User $member): ?OfferPrice` — `points` = the displayed hourly rate, rounded up (E8).
    - `planNow(Plan $plan, ?User $member): ?OfferPrice`.

- [ ] **Step 1: Write the failing tests** (`OfferResolverTest`, `RefreshDatabase`, `Carbon::setTestNow('2026-10-06 08:00:00')` UTC):
```php
private function offer(array $attributes = [], array $targets = []): Coupon
{
    $offer = Coupon::factory()->automatic()->create($attributes);
    foreach ($targets as $target) {
        $offer->targets()->create($target);
    }

    return $offer;
}

public function test_a_percentage_offer_prices_in_window_time_at_an_exact_rate(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 15]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

    $price = app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(3), null, PricingPurpose::Booking, new UsageInFlight);

    $this->assertSame(27, $price->points); // 2 h × 13.5, not 2 × 14
}

public function test_only_the_seconds_inside_the_offer_window_are_discounted(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 70, 'starts_at' => '2026-10-06 10:00:00', 'ends_at' => '2026-10-06 11:00:00'],
        [['purpose' => 'booking']]);

    $price = app(OfferResolver::class)->forSpace($space, Carbon::parse('2026-10-06 10:00'), Carbon::parse('2026-10-06 12:00'), null, PricingPurpose::Booking, new UsageInFlight);

    $this->assertSame(170, $price->points);
}

public function test_the_lowest_total_wins_between_overlapping_offers(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $wholeDay = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);
    $oneHour = $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 70, 'starts_at' => '2026-10-06 10:00:00', 'ends_at' => '2026-10-06 11:00:00'], [['purpose' => 'booking']]);
    $resolver = app(OfferResolver::class);

    $twoHours = $resolver->forSpace($space, Carbon::parse('2026-10-06 10:00'), Carbon::parse('2026-10-06 12:00'), null, PricingPurpose::Booking, new UsageInFlight);
    $this->assertTrue($twoHours->offer->is($wholeDay));
    $this->assertSame(160, $twoHours->points);

    $oneHourBooking = $resolver->forSpace($space, Carbon::parse('2026-10-06 10:00'), Carbon::parse('2026-10-06 11:00'), null, PricingPurpose::Booking, new UsageInFlight);
    $this->assertTrue($oneHourBooking->offer->is($oneHour));
    $this->assertSame(70, $oneHourBooking->points);
}

public function test_a_tie_goes_to_the_offer_ending_first_then_the_lowest_id(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $later = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'ends_at' => '2026-10-20 00:00:00']);
    $sooner = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'ends_at' => '2026-10-10 00:00:00']);
    $openA = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);

    $price = app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), null, PricingPurpose::Booking, new UsageInFlight);
    $this->assertTrue($price->offer->is($sooner));

    $sooner->delete();
    $later->delete();
    $openB = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);
    $price = app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), null, PricingPurpose::Booking, new UsageInFlight);
    $this->assertTrue($price->offer->is($openA));
}

public function test_amount_off_and_the_cap_apply_to_time(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $this->offer(['discount_type' => 'amount_off', 'discount_value' => 30]);
    $this->assertSame(140, app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(3), null, PricingPurpose::Booking, new UsageInFlight)->points);

    Coupon::query()->delete();
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 50, 'max_discount_points' => 30]);
    $this->assertSame(170, app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(3), null, PricingPurpose::Booking, new UsageInFlight)->points);
}

public function test_a_fixed_price_at_or_above_the_rate_is_ignored(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 100], [['purpose' => 'booking']]);

    $this->assertNull(app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), null, PricingPurpose::Booking, new UsageInFlight));
}

public function test_ineligible_offers_are_dropped_silently(): void
{
    $member = User::factory()->create();
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $someoneElses = $this->offer(['discount_type' => 'percentage', 'discount_value' => 50]);
    $someoneElses->users()->attach(User::factory()->create());
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 40, 'is_active' => false]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 30, 'starts_at' => '2026-11-01 00:00:00']);
    $usedUp = $this->offer(['discount_type' => 'percentage', 'discount_value' => 25, 'usage_limit' => 1]);
    // one live booking already used $usedUp (factory booking + AppliedDiscount offer row)
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

    $price = app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), $member, PricingPurpose::Booking, new UsageInFlight);

    $this->assertSame(90, $price->points);
}

public function test_without_a_member_only_offers_for_everyone_apply(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 50, 'new_members_only' => true]);
    $company = Company::factory()->create();
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 40, 'company_id' => $company->id]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 10, 'per_user_limit' => 1]);

    $price = app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), null, PricingPurpose::Booking, new UsageInFlight);

    $this->assertSame(90, $price->points);
}

public function test_an_offer_for_another_target_never_applies(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $plan = Plan::factory()->create(['price_points' => 120]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 20], [['purpose' => 'plan_purchase', 'plan_id' => $plan->id]]);

    $this->assertNull(app(OfferResolver::class)->forSpace($space, now()->addHour(), now()->addHours(2), null, PricingPurpose::Booking, new UsageInFlight));
    $this->assertSame(96, app(OfferResolver::class)->forItem(120, PricingTarget::forPlan($plan), null, now(), new UsageInFlight)->points);
}

public function test_an_offer_whose_minimum_is_not_met_does_not_apply(): void
{
    $plan = Plan::factory()->create(['price_points' => 120]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'min_amount_points' => 200]);

    $this->assertNull(app(OfferResolver::class)->forItem(120, PricingTarget::forPlan($plan), null, now(), new UsageInFlight));
}

public function test_the_displayed_hourly_rate_rounds_up(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 15]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

    $this->assertSame(14, app(OfferResolver::class)->hourlyNow($space, null)->points);
}

public function test_an_open_ended_offer_has_no_end(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

    $this->assertNull(app(OfferResolver::class)->hourlyNow($space, null)->endsAt);
}
```
`SaleWindowTest`: `between(null, null)` is live always and clips nothing off; `between($a, null)` clips from `$a`; `between(null, $b)` until `$b`; `overlaps()` false for a range wholly outside.
`SpaceRateTimelineTest`: no offer → one segment at the rate; percentage 10 on 15 → `'13.50'` inside, `'15'` outside; fixed price 100 on rate 100 → one segment at the rate (ignored).

- [ ] **Step 2:** Run the three files — FAIL.

- [ ] **Step 3: Implement**

`SpaceRateTimeline`:
```php
public function segments(Space $space, CarbonInterface $from, CarbonInterface $to, ?CouponTerms $offer = null): array
{
    $rate = $space->hourly_rate_points;
    $total = (int) $from->diffInSeconds($to);

    if ($rate === null || $total <= 0) {
        return [];
    }

    $offerRate = $offer !== null ? $this->offerRate((int) $rate, $offer) : null;
    $inside = $offerRate !== null ? ($offer->window ?? SaleWindow::between(null, null))->clip($from, $to) : null;

    if ($inside === null) {
        return [new RateSegment($total, (int) $rate)];
    }

    $offerSeconds = (int) $inside[0]->diffInSeconds($inside[1]);
    $segments = [new RateSegment($offerSeconds, $offerRate)];

    if ($total > $offerSeconds) {
        $segments[] = new RateSegment($total - $offerSeconds, (int) $rate);
    }

    return $segments;
}

/** The hourly rate inside the offer, exact; null when it is no lower than the rate (E3, E1). */
public function offerRate(int $rate, CouponTerms $offer): ?string
{
    $offered = match ($offer->type) {
        CouponDiscountType::Percentage => bcdiv(bcmul((string) $rate, (string) (100 - $offer->value)), '100', 2),
        CouponDiscountType::AmountOff => (string) max(0, $rate - $offer->value),
        CouponDiscountType::FixedPrice => (string) $offer->value,
    };

    return bccomp($offered, (string) $rate, 2) < 0 ? $offered : null;
}
```
`OfferResolver` (inject `AmountCalculator`, `SpaceRateTimeline`, `CouponCalculator`, `CouponLedger`, `AppliesToTarget`, `MemberIsInAudience`, `IsForNewMembers`, `IsWithinUsageLimits`):
```php
/** Active offers, targets loaded — read once per instance (a listing prices many cards). */
private ?Collection $live = null;

private function candidates(PricingTarget $target, ?User $member, UsageInFlight $inFlight): Collection
{
    $this->live ??= Coupon::query()->automatic()->where('is_active', true)->with('targets')->orderBy('id')->get();

    return $this->live->filter(fn (Coupon $offer) => $member === null
        ? $this->forEveryone($offer, $target)
        : collect([$this->appliesTo, $this->audience, $this->newMembers, $this->limits])
            ->every(fn (CouponRule $rule) => $rule->rejection($offer, $member, $target, $inFlight, now()) === null));
}

/** No member to check an audience against: only an offer for everyone, within its total limit (E4, Review Focus 1). */
private function forEveryone(Coupon $offer, PricingTarget $target): bool
{
    return $offer->company_id === null
        && ! $offer->new_members_only
        && ! $offer->users()->exists()
        && ($offer->usage_limit === null || $this->ledger->liveUsage($offer) < $offer->usage_limit)
        && $this->appliesTo->rejection($offer, new User, $target, new UsageInFlight, now()) === null;
}

public function forSpace(Space $space, CarbonInterface $from, CarbonInterface $to, ?User $member, PricingPurpose $purpose, UsageInFlight $inFlight): ?OfferPrice
{
    $list = $this->amounts->forRange($space, $from, $to);

    return $this->best(
        $this->candidates(PricingTarget::forSpace($purpose, $space), $member, $inFlight)
            ->filter(fn (Coupon $offer) => $offer->window()->overlaps($from, $to)),
        $list,
        fn (CouponTerms $terms) => $this->priceSpace($space, $from, $to, $terms),
    );
}

public function forItem(int $list, PricingTarget $target, ?User $member, CarbonInterface $at, UsageInFlight $inFlight): ?OfferPrice
{
    return $this->best(
        $this->candidates($target, $member, $inFlight)->filter(fn (Coupon $offer) => $offer->window()->isLiveAt($at)),
        $list,
        fn (CouponTerms $terms) => $this->priceItem($list, $terms),
    );
}

public function priceSpace(Space $space, CarbonInterface $from, CarbonInterface $to, CouponTerms $terms): int
{
    $list = $this->amounts->forRange($space, $from, $to);
    $price = $this->amounts->forSegments($this->timeline->segments($space, $from, $to, $terms));

    return $terms->maxDiscountPoints !== null ? max($price, $list - $terms->maxDiscountPoints) : $price;
}

public function priceItem(int $list, CouponTerms $terms): int
{
    return $list - $this->calculator->discountFor($terms, $list);
}

/** @param  callable(CouponTerms): int  $price */
private function best(Collection $offers, int $list, callable $price): ?OfferPrice
{
    return $offers
        ->reject(fn (Coupon $offer) => $offer->min_amount_points !== null && $list < $offer->min_amount_points)
        ->map(fn (Coupon $offer) => new OfferPrice($offer, $price(CouponTerms::fromCoupon($offer)), $offer->window()->endsAt))
        ->filter(fn (OfferPrice $p) => $p->points < $list)
        ->sort(fn (OfferPrice $a, OfferPrice $b) => [$a->points, $a->endsAt?->getTimestamp() ?? PHP_INT_MAX, $a->offer->id]
            <=> [$b->points, $b->endsAt?->getTimestamp() ?? PHP_INT_MAX, $b->offer->id])
        ->first();
}

public function hourlyNow(Space $space, ?User $member): ?OfferPrice
{
    $rate = (int) ($space->hourly_rate_points ?? 0);
    $best = $this->forSpace($space, now(), now()->addHour(), $member, PricingPurpose::Booking, new UsageInFlight);

    if ($best === null || $rate <= 0 || ! $best->offer->window()->isLiveAt(now())) {
        return null;
    }

    $exact = $this->timeline->offerRate($rate, CouponTerms::fromCoupon($best->offer));
    $whole = bcdiv($exact, '1', 0);
    // E8: shown rounded up, never understating the price.
    $shown = (int) (bccomp($exact, $whole, 2) > 0 ? bcadd($whole, '1') : $whole);

    return new OfferPrice($best->offer, $shown, $best->endsAt);
}

public function planNow(Plan $plan, ?User $member): ?OfferPrice
{
    return $this->forItem((int) $plan->price_points, PricingTarget::forPlan($plan), $member, now(), new UsageInFlight);
}
```
Note on `forEveryone`: `AppliesToTarget::rejection` ignores the member, so passing an unsaved `new User` is safe; leave a one-line comment saying so.

`Coupon::window()`: `return SaleWindow::between($this->starts_at, $this->ends_at);`

- [ ] **Step 4:** Run the three files — PASS; then `php artisan test tests/Unit/Domain/Pricing` — PASS.
- [ ] **Step 5:** Commit `feat(pricing): the best automatic offer, priced by usage time`.

---

### Task 5: Offers replace sales in the pipeline (new prices)

**Files:**
- Create: `database/migrations/2026_10_06_100300_drop_sale_columns.php`
- Delete: `app/Domain/Pricing/Concerns/HasSalePrice.php`, `app/Http/Requests/Concerns/ValidatesSaleWindow.php`, `tests/Feature/Pricing/AdminSaleFieldsTest.php`, `tests/Feature/Pricing/SpaceSalePriceTest.php`, `tests/Feature/Pricing/PlanSaleColumnsTest.php`
- Modify: `app/Domain/Pricing/Enums/PriceChoice.php`, `app/Domain/Pricing/ValueObjects/{PriceQuote,PriceLine,SaleWindow}.php`, `app/Domain/Pricing/Services/{PriceQuoteService,QuoteRecorder,CouponLedger}.php`, `app/Domain/Foundation/Models/Space.php`, `app/Domain/Membership/Models/Plan.php`, `app/Http/Requests/Admin/{StorePlanRequest,StoreSpaceRequest}.php`, `app/Http/Resources/{PlanResource,SpaceResource}.php`, `app/Domain/Booking/Services/{BookingCreationService,BookingBatchCreationService,BookingPresentationService}.php`, `app/Services/Experience/EventRegistrationService.php`, `app/Domain/Finance/Payments/PaymentService.php`, `app/Domain/Finance/Services/StartPayment.php`, `app/Http/Controllers/Api/V1/Member/{MembershipController,PaymentController}.php`, `lang/{en,ar}/api.php` (drop `admin.sale_not_below_price`)
- Rewrite onto offers: `tests/Feature/Pricing/{PriceQuoteBookingTest,PlanSalePriceTest,PlanCouponPurchaseTest,BookingCouponCreationTest,BookingBatchCouponTest,EventRegistrationCouponTest}.php`; rename `PlanSalePriceTest` → `PlanOfferPriceTest`.

**Interfaces:**
- Consumes: `OfferResolver::forSpace/forItem` (Task 4), `CouponLedger::write` (Task 3).
- Produces:
  - `enum PriceChoice { List='list'; Offer='offer'; Plan='plan'; }`.
  - `PriceQuote` gains trailing named params `?Coupon $offer = null`, `?PlanSaving $saving = null` (PlanSaving class arrives in Task 8 — type it `?PlanSaving` then; this task adds only `$offer`), and `appliedOffer(): ?Coupon` (`choice === Offer ? offer : null`). `lines()` emits `new PriceLine('sale', -$automaticPoints, name: $this->offer?->translate('name'))`; `earmark()` adds the applied offer with `$inFlight->addCoupon($offer)`.
  - `PriceLine` gains `?string $name = null`.
  - `CouponLedger::lockForPricing(?Coupon $coupon): ?Coupon` — replaces `lockForRedemption()` at every pricing entry point.
  - `QuoteRecorder::record()` writes an `offer` row: `coupon_id`, terms copy, `base_points = listPoints`, `points = automaticPoints`, `terms = ['name' => $offer->name, 'starts_at' => iso|null, 'ends_at' => iso|null]`.

- [ ] **Step 1: Write the failing tests**

In `PriceQuoteBookingTest` (replace the sale cases):
```php
public function test_a_booking_takes_the_better_of_an_offer_and_the_plan_discount(): void
{
    // rate 100, 2 h; plan discount 20% → 160; offer 25% → 150 → offer wins, choice Offer, lines base 200 / sale -50
}

public function test_a_tie_takes_the_offer_and_spends_no_plan_discount_use(): void
{
    // plan discount 20%, offer 20% → choice Offer; after creating the booking: no PlanDiscountRedemption, one 'offer' ledger row
}

public function test_a_combinable_coupon_stacks_on_the_offer(): void
{
    // rate 100, 2 h, offer 25% → 150; coupon 10% combinable → 135; ledger rows offer(50) + coupon(15, base 150)
}

public function test_a_non_combinable_coupon_goes_on_the_list_price_when_lower(): void
{
    // offer 25% → 150; coupon 50% non-combinable → 100 < 150 → choice List, coupon 100 off 200; no offer row
}

public function test_a_non_combinable_coupon_loses_to_a_better_offer(): void
{
    // offer 25% → 150; coupon 10% non-combinable → 180 > 150 → outcome offer_is_better; only the offer row
}

public function test_the_breakdown_labels_the_offer_with_its_name(): void
{
    // offer name {"en":"Meeting rooms week"} → breakdown line key 'sale', label 'Meeting rooms week'
    // offer with no name → label __('api.booking.sale_label') ('Offer')
}
```
`BookingBatchCouponTest` (Review Focus 4):
```php
public function test_an_offer_with_one_use_left_prices_only_the_first_date_of_a_batch(): void
{
    // offer 50%, usage_limit 1; batch of two dates, rate 100, 1 h each → prices [50, 100]; one 'offer' row, on the first booking
}
```
`PlanOfferPriceTest`:
```php
public function test_a_fixed_price_offer_prices_a_plan_purchase(): void
{
    // plan 120; offer fixed_price 100 targeting plan_purchase+plan → payment amount_points 100; ledger offer row base 120 points 20 percent 16
}

public function test_the_offer_live_when_the_payment_opened_holds_after_it_ends(): void
{
    // open a cash plan payment while the offer is live; travel past ends_at; fulfiller pointsDue still 100
}

public function test_a_free_plan_through_an_offer_takes_the_free_path(): void
{
    // plan 120, offer percentage 100 → POST member membership purchase with no coupon → activated without a payment, like a 100% coupon (Review Focus 3)
}
```
`EventRegistrationCouponTest`: a paid event at 50 with a 20% offer targeting the event → attendee `price_points` 40, an `offer` ledger row.

- [ ] **Step 2:** Run `php artisan test tests/Feature/Pricing` — FAIL.

- [ ] **Step 3: Implement**

Migration `100300`: drop `sale_hourly_rate_points`, `sale_starts_at`, `sale_ends_at` from `spaces`; `sale_price_points`, `sale_starts_at`, `sale_ends_at` from `plans`; `down()` re-adds them nullable. Docblock: phase 1 never reached production, so nothing migrates (spec "Context").

`Space`/`Plan`: remove `HasSalePrice`, the sale fillables/casts and `salePriceColumn()`. `SaleWindow`: remove `of()` and `$points`; constructor private, `between()` the only factory. Requests: remove `ValidatesSaleWindow` use, `interpretSaleWindow()`, `saleWindowRules()`, `assertSaleBelowPrice()`; drop the now-empty `prepareForValidation`/`withValidator` and the `InterpretsLocalTimes` use if nothing else needs it. Resources: drop the sale fields.

`CouponLedger::lockForPricing()`:
```php
/**
 * The first lock — and the first read — of a transaction that may spend an
 * offer or a coupon: the typed coupon and every active offer with a limit,
 * in id order, in one locking read, so two purchases cannot both take "the
 * last" use and two transactions never lock them in opposite orders. No
 * relation filter: a subquery here would be a plain read and fix the
 * snapshot before the lock.
 */
public function lockForPricing(?Coupon $coupon): ?Coupon
{
    $locked = Coupon::query()
        ->where(fn (Builder $q) => $q->where('is_automatic', true)->where('is_active', true)
            ->where(fn (Builder $limited) => $limited->whereNotNull('usage_limit')->orWhereNotNull('per_user_limit')))
        ->when($coupon !== null, fn (Builder $q) => $q->orWhereKey($coupon->getKey()))
        ->orderBy('id')
        ->lockForUpdate()
        ->get();

    return $coupon !== null ? $locked->firstWhere('id', $coupon->getKey()) ?? throw (new ModelNotFoundException)->setModel(Coupon::class) : null;
}
```
Replace `lockForRedemption($coupon)` with `lockForPricing($coupon)` in `BookingCreationService`, `BookingBatchCreationService`, `PaymentService`, `StartPayment`, `EventRegistrationService` — called **unconditionally** (it also locks offers when no coupon is typed). `CouponApplication` keeps `lockForRedemption()` (no offer is chosen there). Update the class docblock's locking protocol.

`PriceQuoteService` (inject `OfferResolver`):
```php
public function forBooking(Space $space, CarbonInterface $start, CarbonInterface $end, ?User $member, ?Coupon $coupon = null, ?UsageInFlight $inFlight = null): PriceQuote
{
    $inFlight ??= new UsageInFlight;
    $list = $this->amounts->forRange($space, $start, $end);
    $offer = $this->offers->forSpace($space, $start, $end, $member, PricingPurpose::Booking, $inFlight);
    // … plan discount unchanged …
    return $this->compose($list, $offer, $discount, $planPrice, $member, $coupon, PricingTarget::forSpace(PricingPurpose::Booking, $space), $inFlight);
}

public function forPlan(Plan $plan, ?User $member, ?Coupon $coupon, CarbonInterface $asOf, ?UsageInFlight $inFlight = null): PriceQuote
{
    $inFlight ??= new UsageInFlight;
    $list = (int) $plan->price_points;
    $offer = $this->offers->forItem($list, PricingTarget::forPlan($plan), $member, $asOf, $inFlight);

    return $this->compose($list, $offer, null, $list, $member, $coupon, PricingTarget::forPlan($plan), $inFlight);
}

public function forEvent(Event $event, ?User $member, ?Coupon $coupon): PriceQuote
{
    $list = ($event->is_free ?? true) ? 0 : (int) ($event->price_points ?? 0);
    // coupon on a free event refused, unchanged
    $offer = $list > 0 ? $this->offers->forItem($list, PricingTarget::forEvent($event), $member, now(), new UsageInFlight) : null;

    return $this->compose($list, $offer, null, $list, $member, $coupon, PricingTarget::forEvent($event), new UsageInFlight);
}

private function compose(int $list, ?OfferPrice $offer, ?PlanDiscount $discount, int $planPrice, ?User $member, ?Coupon $coupon, PricingTarget $target, UsageInFlight $inFlight): PriceQuote
{
    $offerPrice = $offer?->points ?? $list;

    // The better automatic reduction; a tie takes the offer.
    [$choice, $automatic] = match (true) {
        $offerPrice < $list && $offerPrice <= $planPrice => [PriceChoice::Offer, $offerPrice],
        $discount !== null && $planPrice < $list => [PriceChoice::Plan, $planPrice],
        default => [PriceChoice::List, $list],
    };
    // … coupon branches unchanged; quote() passes offer: $choice === PriceChoice::Offer ? $offer->offer : null
}
```
`forCouponOn()`: `PriceChoice::Sale` → `PriceChoice::Offer`. Delete `planSalePrice()`. Update the class docblock's algorithm block to the spec's (L, O, P, A, coupon).

`PaymentService::start()` — a plan purchase is always quoted now (it may carry an offer even without a coupon):
```php
$coupon = $this->couponLedger->lockForPricing($coupon);
$quote = $purpose === PaymentPurpose::PlanPurchase ? $this->quotes->forPlan($context['plan'], $payer, $coupon, now()) : null;
// …
// The offer's and the coupon's uses are filed on the row before what is
// due is read, so the fulfiller prices the payment with them.
if ($quote !== null && ($quote->appliedOffer() !== null || $quote->appliedCoupon() !== null)) {
    $payment->amount_points = 0;
    $payment->save();
    $this->recorder->record($quote, $payment, $payer, PricingPurpose::PlanPurchase);
}
```
`StartPayment` already quotes plans; switch to `lockForPricing` and `recorder->record`.

`MembershipController` (line ~101) and `PaymentController` (line ~106): drop the `$coupon !== null &&` guard so "free" is decided on the quote itself: `$free = $quotes->forPlan($plan, $member, $coupon, now())->finalPoints === 0;`.

`BookingPresentationService::breakdownOf()`: `'sale' => $line->name ?? __('api.booking.sale_label', [], $locale),`.

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing tests/Feature/Booking tests/Feature/Membership tests/Feature/Finance tests/Guards` — PASS except the locked-terms tests owned by Task 6 (`BookingExtensionPricingTest`, `SessionClosurePricingTest` sale cases) — mark those methods `$this->markTestSkipped('Task 6')` and list them in the commit body.
- [ ] **Step 5:** Commit `feat(pricing): automatic offers replace item sales`.

---

### Task 6: Offers at locked terms (extension, closure, plan payment, existing booking)

**Files:**
- Modify: `app/Domain/Pricing/Services/{PriceQuoteService,QuoteRecorder}.php`, `app/Domain/Booking/Services/{BookingExtensionService,BookingExtensionPlanner,SessionClosureService}.php`
- Test: `tests/Feature/Pricing/{BookingExtensionPricingTest,SessionClosurePricingTest,PlanOfferPriceTest}.php` (un-skip and rewrite the Task 5 skips)

**Interfaces:**
- Consumes: `CouponLedger::offerFor/couponFor/planDiscountFor`, `CouponTerms::fromLedger`, `OfferResolver::priceSpace/priceItem/forSpace`.
- Produces:
  - `PriceQuoteService::forExtension(Booking, Space, CarbonInterface $newEnd, ?PlanDiscountRedemption)` — same return shape; order: active plan redemption → plan; else the booking's `offer` row at its copied terms and window (`priceSpace`); else list.
  - `PriceQuoteService::forSession(Booking|WalkinSession, from, to)` — locked offer row → `priceSpace` at copied terms; else a booking's plan discount; else a fresh `OfferResolver::forSpace(..., $session->user, Booking|CoworkingSession purpose, new UsageInFlight)` (spec "Locked terms": picked at closure, over the time used).
  - `PriceQuoteService::forPlanPayment(Payment, Plan)` — the payment's `offer` row via `priceItem(list, fromLedger)`, else list.
  - `PriceQuoteService::forExistingBooking(Booking)` — reads `offer`/`plan_discount`/`coupon` rows' `points`; the offer is `$row->coupon`.
  - `QuoteRecorder::reprice(PriceQuote $quote, Model $record, User $member, PricingPurpose $purpose): void` — upserts the offer/plan/coupon rows for the new price (creates the offer row when closure picked one). Signature change: update `SessionClosureService` call.
  - `QuoteRecorder::recordExtension(Booking $booking, array $price): void` — updates the booking's `offer` or `plan_discount` row to `base_points = undiscounted_total`, `points = undiscounted_total − total`, percent recomputed.

- [ ] **Step 1: Write the failing tests**
```php
// BookingExtensionPricingTest
public function test_an_extension_keeps_the_offer_the_booking_was_priced_with_even_after_it_is_switched_off(): void
{
    // rate 100, offer 50% (window whole day), book 1 h → 50; deactivate the offer and change it to 10%;
    // extend by 1 h → difference 50 (copied 50% terms), booking price 100, offer row base 200 points 100
}

public function test_an_extension_of_a_list_priced_booking_takes_no_new_offer(): void
{
    // book 1 h with no offer → 100; create a 50% offer; extend 1 h → difference 100
}

public function test_an_extension_past_the_offer_window_pays_the_rate_for_the_time_outside(): void
{
    // offer 50% window ends at 11:00; booking 10:00–11:00 → 50; extend to 12:00 → difference 100
}

// SessionClosurePricingTest
public function test_a_walk_in_picks_its_offer_at_closure_and_locks_it(): void
{
    // co_space rate 10, offer 20% targeting coworking_session; walk-in 2 h → price 16; one 'offer' ledger row on the walk-in, base 20, points 4
}

public function test_an_unpaid_booking_closed_early_keeps_its_locked_offer_terms(): void
{
    // book 2 h with 50% offer → 100; edit the offer to 10%; check out after 1 h unpaid → price 50; offer row base 100 points 50
}

// PlanOfferPriceTest
public function test_a_plan_payment_is_priced_at_its_locked_offer_after_the_offer_is_edited(): void
{
    // open cash payment with 25% offer on plan 120 → 90; edit offer to 50% → pointsDue still 90
}
```
- [ ] **Step 2:** Run the three files — FAIL.
- [ ] **Step 3: Implement**

`forExtension`:
```php
$offerRow = $redemption === null ? $this->ledger->offerFor($booking) : null;

[$old, $new] = match (true) {
    $redemption !== null => [$this->planDiscounts->apply($oldList, $redemption->discount), $this->planDiscounts->apply($newList, $redemption->discount)],
    $offerRow !== null => [
        $this->offers->priceSpace($space, $booking->start_at, $booking->end_at, CouponTerms::fromLedger($offerRow)),
        $this->offers->priceSpace($space, $booking->start_at, $newEnd, CouponTerms::fromLedger($offerRow)),
    ],
    default => [$oldList, $newList],
};
```
`atLockedTerms(int $list, ?OfferPrice $offer, ?PlanDiscount $discount, ?AppliedDiscount $couponRow): PriceQuote` — replaces the `callable $salePrice` with the already-computed offer; choice `Offer` when `$offer !== null && $offer->points < $list`.

`forSession`:
```php
$space = $session->space;
$list = $this->amounts->forRange($space, $from, $to);
$offerRow = $this->ledger->offerFor($session);
$planRedemption = $session instanceof Booking ? PlanDiscountRedemption::query()->where('booking_id', $session->id)->first() : null;

$offer = match (true) {
    $offerRow !== null => new OfferPrice($offerRow->coupon, $this->offers->priceSpace($space, $from, $to, CouponTerms::fromLedger($offerRow)), null),
    $planRedemption !== null => null,
    default => $this->offers->forSpace($space, $from, $to, $session->user, $session instanceof Booking ? PricingPurpose::Booking : PricingPurpose::CoworkingSession, new UsageInFlight),
};

return $this->atLockedTerms($list, $offer, $planRedemption?->discount, $this->ledger->couponFor($session));
```
(`$offerRow->coupon` is non-null: `coupon_id` restricts on delete.)

`SessionClosureService::finalizeClosure()`: before pricing, inside its transaction, call `$this->couponLedger->lockForPricing(null)` as the first lock when the session is a walk-in or an unpaid booking without an offer/plan row; pass member and purpose to `reprice()`.

`BookingExtensionService` (line ~114): after the plan redemption update, call `$this->recorder->recordExtension($lockedBooking, $price)`.

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing tests/Feature/Booking` — PASS, no skipped methods left (`grep -rn "markTestSkipped('Task 6')" tests` is empty).
- [ ] **Step 5:** Commit `feat(pricing): extensions and check-outs keep the offer they were priced with`.

---

### Task 7: What the member sees — `sale` per member

**Files:**
- Modify: `app/Support/Presenters/PriceLabelPresenter.php`, `app/Domain/Foundation/Services/SpaceBrowseService.php`, `app/Domain/Membership/Services/PackageBrowseService.php`
- Test: `tests/Feature/Pricing/SaleLabelsTest.php` (rewrite onto offers)

**Interfaces:**
- Consumes: `OfferResolver::hourlyNow`, `OfferResolver::planNow`.
- Produces: `PriceLabelPresenter::sale(?OfferPrice $offer, int $originalPoints, ?User $user, bool $hourly, string $group, ?string $locale = null): ?array` — same keys as today; `ends_at`/`ends_label` are `null` for an open-ended offer. `PackageBrowseService::formatPackage(Plan $plan, bool $isCurrent, ?User $user)`.

- [ ] **Step 1: Write the failing tests** (`SaleLabelsTest`):
```php
public function test_a_space_card_shows_the_offer_live_now_with_the_rate_rounded_up(): void
{
    // rate 15, offer 10% ends 2026-10-12 → GET member spaces → sale.points 14, original_points 15, discount_percent 6, ends_at set
}

public function test_a_members_only_offer_shows_only_to_its_members(): void
{
    // offer 20% users=[$a] → $a sees sale; $b sees sale null; anonymous public package listing sees null (Review Focus 1)
}

public function test_a_package_shows_the_offer_for_this_member(): void
{
    // plan 120, offer fixed_price 100 for plan → sale.points 100, discount_label '16% off'
}

public function test_an_open_ended_offer_has_no_end_label(): void
{
    // offer with no ends_at → sale.ends_at null, sale.ends_label null (Review Focus 2)
}

public function test_an_offer_not_yet_started_is_not_shown(): void
{
    // starts_at tomorrow → sale null (spec "Out of scope: upcoming offers")
}
```
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3: Implement**

`PriceLabelPresenter::sale()`:
```php
public function sale(?OfferPrice $offer, int $originalPoints, ?User $user, bool $hourly, string $group, ?string $locale = null): ?array
{
    if ($offer === null || $offer->points >= $originalPoints) {
        return null;
    }
    // … same body, with $offer->points for $sale->points, and:
    $endsAt = $offer->endsAt !== null ? app(LocalTime::class)->toLocal($offer->endsAt) : null;
    // 'ends_at' => $endsAt?->toIso8601String(),
    // 'ends_label' => $endsAt !== null ? __("{$group}.sale_ends", ['date' => $endsAt->format('Y-m-d H:i')], $locale) : null,
}
```
Update the `@return` shape (`ends_at: ?string, ends_label: ?string`) and the docblock: built from the best automatic offer live now for this member.

`SpaceBrowseService` (two places): `'sale' => $this->prices->sale($this->offers->hourlyNow($space, $user), (int) ($space->hourly_rate_points ?? 0), $user, true, 'api.space')` — inject one `OfferResolver` (its candidate cache then serves the whole listing).

`PackageBrowseService`: thread `?User $user` from `listAvailablePackages` / `getPackageDetails` through `formatAvailablePackages` to `formatPackage`; `'sale' => $this->prices->sale($this->offers->planNow($plan, $user), $pricePoints, $user, false, 'api.package')`.

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing/SaleLabelsTest.php tests/Feature/Membership tests/Feature/Foundation` — PASS.
- [ ] **Step 5:** Commit `feat(pricing): a member sees the offer that is theirs`.

---

### Task 8: Duration and volume savings

**Files:**
- Create: `database/migrations/2026_10_06_100400_add_saving_reference_to_plans_table.php`, `app/Domain/Pricing/ValueObjects/PlanSaving.php`, `app/Domain/Pricing/Services/PlanSavingCalculator.php`
- Modify: `app/Domain/Membership/Models/Plan.php`, `app/Http/Requests/Admin/StorePlanRequest.php`, `app/Http/Resources/PlanResource.php`, `app/Domain/Pricing/ValueObjects/PriceQuote.php`, `app/Domain/Pricing/Services/{PriceQuoteService,QuoteRecorder}.php`, `app/Support/Presenters/PriceLabelPresenter.php`, `app/Domain/Membership/Services/PackageBrowseService.php`, `lang/{en,ar}/api.php`
- Test: new `tests/Unit/Domain/Pricing/PlanSavingCalculatorTest.php` (feature-style, needs DB), new `tests/Feature/Pricing/PlanSavingTest.php`

**Interfaces:**
- Produces:
  - `Plan::referencePlan(): BelongsTo`, `Plan::referenceSpace(): BelongsTo`; fillable `reference_plan_id`, `reference_space_id`, `reference_units`.
  - `final class PlanSaving { __construct(public readonly string $comparedTo /* 'plan'|'space' */, public readonly int $referenceId, public readonly ?string $referenceName, public readonly int $units, public readonly int $referencePoints, public readonly int $savingPoints, public readonly int $savingPercent, public readonly string $perUnitPoints) }` plus `toArray(): array`.
  - `PlanSavingCalculator::for(Plan $plan): ?PlanSaving`.
  - `PriceQuote::$saving` (set by `forPlan`/`forPlanPayment`); `QuoteRecorder::record()` writes a `duration_saving` row on a plan payment: `base_points = referencePoints`, `points = savingPoints`, `terms = ['compared_to', 'reference_id', 'reference_name', 'units']`.
  - `PriceLabelPresenter::saving(?PlanSaving $saving, ?User $user, ?string $locale = null): ?array` → `{compared_to, reference_points, reference_label, saving_points, saving_percent, saving_label, per_unit_label}`.

- [ ] **Step 1: Write the failing tests**
```php
// PlanSavingCalculatorTest
public function test_a_yearly_plan_saves_against_twelve_monthly_ones(): void
{
    $monthly = Plan::factory()->create(['price_points' => 125]);
    $yearly = Plan::factory()->create(['price_points' => 900, 'reference_plan_id' => $monthly->id, 'reference_units' => 12]);

    $saving = app(PlanSavingCalculator::class)->for($yearly);

    $this->assertSame(1500, $saving->referencePoints);
    $this->assertSame(600, $saving->savingPoints);
    $this->assertSame(40, $saving->savingPercent);
    $this->assertSame('75.00', $saving->perUnitPoints);
}

public function test_an_hours_bundle_saves_against_the_hourly_rate(): void
{
    $space = Space::factory()->room()->create(['hourly_rate_points' => 2]);
    $bundle = Plan::factory()->create(['price_points' => 40, 'reference_space_id' => $space->id, 'reference_units' => 25]);

    $saving = app(PlanSavingCalculator::class)->for($bundle);

    $this->assertSame(50, $saving->referencePoints);
    $this->assertSame(20, $saving->savingPercent);
    $this->assertSame('1.60', $saving->perUnitPoints);
}

public function test_the_percent_rounds_down(): void
{
    // reference 3 × 100 = 300, price 200 → saving 100 → 33
}

public function test_no_reference_or_no_saving_is_null(): void
{
    // no reference → null; price ≥ reference → null
}

public function test_the_reference_list_price_is_used_never_its_offer_price(): void
{
    // monthly 125 with a live 50% offer; yearly 900 ref ×12 → reference_points 1500 (E7)
}

// PlanSavingTest (feature)
public function test_admin_sets_and_clears_a_reference(): void { /* PATCH plan with reference_plan_id+units → 200; resource saving shown on admin GET; PATCH nulls → saving null */ }
public function test_a_reference_must_be_exactly_one_kind_with_units(): void { /* both refs → 422; ref without units → 422; units without ref → 422; units 0 → 422 */ }
public function test_a_plan_cannot_reference_itself(): void { /* PATCH plan reference_plan_id = own id → 422 */ }
public function test_the_package_shows_its_saving_beside_its_offer(): void { /* member package details: saving.saving_percent 40 and sale block both present */ }
public function test_a_plan_purchase_records_its_saving_in_the_ledger(): void { /* buy yearly in points → applied_discounts duration_saving row base 1500 points 600 percent 40 */ }
public function test_a_non_combinable_coupon_is_allowed_on_a_plan_with_a_saving(): void { /* yearly 900, coupon 10% non-combinable → 810, outcome applied */ }
```
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3: Implement**

Migration:
```php
Schema::table('plans', function (Blueprint $table) {
    $table->foreignId('reference_plan_id')->nullable()->after('price_points')->constrained('plans')->nullOnDelete();
    $table->foreignId('reference_space_id')->nullable()->after('reference_plan_id')->constrained('spaces')->nullOnDelete();
    $table->unsignedInteger('reference_units')->nullable()->after('reference_space_id');
});
```
`PlanSavingCalculator::for()`:
```php
$units = (int) $plan->reference_units;
[$comparedTo, $reference, $unitPrice] = match (true) {
    $plan->reference_plan_id !== null && $plan->referencePlan !== null => ['plan', $plan->referencePlan, (int) $plan->referencePlan->price_points],
    $plan->reference_space_id !== null && $plan->referenceSpace !== null => ['space', $plan->referenceSpace, (int) ($plan->referenceSpace->hourly_rate_points ?? 0)],
    default => [null, null, 0],
};

if ($reference === null || $units < 1 || $unitPrice <= 0) {
    return null;
}

$referencePoints = $unitPrice * $units;
$savingPoints = $referencePoints - (int) $plan->price_points;

if ($savingPoints <= 0) {
    return null;
}

return new PlanSaving(
    comparedTo: $comparedTo,
    referenceId: $reference->id,
    referenceName: $comparedTo === 'plan' ? $reference->translate('name') : $reference->displayTitle(),
    units: $units,
    referencePoints: $referencePoints,
    savingPoints: $savingPoints,
    savingPercent: intdiv($savingPoints * 100, $referencePoints),
    perUnitPoints: bcdiv((string) $plan->price_points, (string) $units, 2),
);
```
`StorePlanRequest` rules:
```php
'reference_plan_id' => ['nullable', 'integer', 'exists:plans,id', 'prohibits:reference_space_id'],
'reference_space_id' => ['nullable', 'integer', 'exists:spaces,id'],
'reference_units' => ['nullable', 'integer', 'min:1', 'required_with:reference_plan_id,reference_space_id'],
```
and in `withValidator` after-hook: units sent non-null with neither reference (as sent, else as stored) → error `api.admin.plan_reference_units_without_reference`; `reference_plan_id` equal to the route plan's id → error `api.admin.plan_reference_self`.

`PlanResource`: always `reference_plan_id`, `reference_space_id`, `reference_units`; on admin routes `'saving' => app(PlanSavingCalculator::class)->for($this->resource)?->toArray()`.

`PriceLabelPresenter::saving()`:
```php
if ($saving === null) {
    return null;
}

$locale ??= app()->getLocale();
// The per-unit price is shown rounded up, as an offer rate is (E8).
$whole = bcdiv($saving->perUnitPoints, '1', 0);
$perUnit = (int) (bccomp($saving->perUnitPoints, $whole, 2) > 0 ? bcadd($whole, '1') : $whole);
$unitPrice = $saving->comparedTo === 'space'
    ? $this->hourly($perUnit, $user, $locale)['price_label']
    : __('api.package.per_unit', ['amount' => $this->total($perUnit, $user, $locale)['price'], 'unit' => $saving->referenceName], $locale);

return [
    'compared_to' => $saving->comparedTo,
    'reference_points' => $saving->referencePoints,
    'reference_label' => $this->total($saving->referencePoints, $user, $locale)['price'],
    'saving_points' => $saving->savingPoints,
    'saving_percent' => $saving->savingPercent,
    'saving_label' => $saving->savingPercent >= 1
        ? __('api.package.saving_percent', ['percent' => $saving->savingPercent], $locale)
        : __('api.package.saving_amount', ['amount' => $this->total($saving->savingPoints, $user, $locale)['price']], $locale),
    'per_unit_label' => $unitPrice,
];
```
`PriceLabelPresenter` lives in `app/Support`, outside the bcmath-only guard; keep bcmath anyway for consistency.

Lang (`en` / `ar`):
- `admin.plan_reference_units_without_reference`: "Reference units need a reference plan or space." / "عدد الوحدات يحتاج إلى باقة أو مساحة مرجعية."
- `admin.plan_reference_self`: "A plan cannot be its own reference." / "لا يمكن أن تكون الباقة مرجعاً لنفسها."
- `package.saving_percent`: "Save :percent%" / "وفّر :percent%"
- `package.saving_amount`: "Save :amount" / "وفّر :amount"
- `package.per_unit`: ":amount per :unit" / ":amount لكل :unit"

`PackageBrowseService::formatPackage()`: `'saving' => $this->prices->saving($this->savings->for($plan), $user)` next to `sale`; eager-load `referencePlan`, `referenceSpace` in the listing query.

`PriceQuoteService::forPlan` and `forPlanPayment` pass `saving: $this->savings->for($plan)`; `QuoteRecorder::record()` writes the `DurationSaving` row when `$quote->saving !== null` and the record is a `Payment`. The `PaymentService` "record before pointsDue" condition gains `|| $quote->saving !== null`.

- [ ] **Step 4:** Run `php artisan test tests/Unit/Domain/Pricing tests/Feature/Pricing tests/Feature/Membership tests/Unit/Domain/Pricing/PricingLangKeysTest.php` — PASS.
- [ ] **Step 5:** Commit `feat(pricing): a plan shows and records what it saves`.

---

### Task 9: Admin offers on the coupon endpoints

**Files:**
- Modify: `app/Http/Requests/Admin/{StoreCouponRequest,UpdateCouponRequest}.php`, `app/Http/Controllers/Api/V1/Admin/CouponController.php`, `app/Http/Resources/CouponResource.php`, `lang/{en,ar}/api.php`
- Test: `tests/Feature/Pricing/AdminCouponControllerTest.php` (add), new `tests/Feature/Pricing/AdminOfferControllerTest.php`

**Interfaces:**
- Produces: `POST admin/coupons` with `is_automatic: true` and no `code` creates an offer; `GET admin/coupons?is_automatic=1|0`; `CouponResource` returns `is_automatic`. `is_automatic` cannot change on update.

- [ ] **Step 1: Write the failing tests** (`AdminOfferControllerTest`, acting as admin like `AdminCouponControllerTest`):
```php
public function test_an_offer_is_created_without_a_code(): void
{
    $this->postJson('/api/v1/admin/coupons', ['is_automatic' => true, 'discount_type' => 'percentage', 'discount_value' => 20, 'name' => ['en' => 'Week', 'ar' => 'أسبوع']])
        ->assertCreated()->assertJsonPath('data.is_automatic', true)->assertJsonPath('data.code', null);
}

public function test_an_offer_refuses_a_code_and_a_coupon_requires_one(): void
{
    $this->postJson('/api/v1/admin/coupons', ['is_automatic' => true, 'code' => 'X1', 'discount_type' => 'percentage', 'discount_value' => 20])->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/admin/coupons', ['discount_type' => 'percentage', 'discount_value' => 20])->assertJsonValidationErrors('code');
}

public function test_a_fixed_price_is_for_offers_only(): void
{
    $this->postJson('/api/v1/admin/coupons', ['code' => 'FP', 'discount_type' => 'fixed_price', 'discount_value' => 100, 'targets' => [['purpose' => 'plan_purchase']]])->assertJsonValidationErrors('discount_type');
}

public function test_a_fixed_price_offer_needs_targets_of_one_family(): void
{
    $base = ['is_automatic' => true, 'discount_type' => 'fixed_price', 'discount_value' => 100];
    $this->postJson('/api/v1/admin/coupons', $base)->assertJsonValidationErrors('targets');
    $this->postJson('/api/v1/admin/coupons', $base + ['targets' => [['purpose' => 'booking'], ['purpose' => 'plan_purchase']]])->assertJsonValidationErrors('targets');
    $this->postJson('/api/v1/admin/coupons', $base + ['targets' => [['purpose' => 'booking'], ['purpose' => 'coworking_session']]])->assertCreated();
}

public function test_offers_and_coupons_are_listed_apart(): void
{
    Coupon::factory()->automatic()->create();
    Coupon::factory()->create();
    $this->getJson('/api/v1/admin/coupons?is_automatic=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_automatic', true);
    $this->getJson('/api/v1/admin/coupons?is_automatic=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_automatic', false);
}

public function test_an_update_cannot_turn_a_coupon_into_an_offer(): void
{
    $coupon = Coupon::factory()->create();
    $this->patchJson("/api/v1/admin/coupons/{$coupon->id}", ['is_automatic' => true])->assertOk();
    $this->assertFalse($coupon->fresh()->is_automatic);
}

public function test_a_used_offer_cannot_be_deleted(): void
{
    // offer with one applied_discounts 'offer' row → DELETE → 409
}
```
- [ ] **Step 2:** Run — FAIL.
- [ ] **Step 3: Implement**

`StoreCouponRequest`:
```php
protected function isAutomatic(): bool
{
    return $this->boolean('is_automatic');
}
```
`UpdateCouponRequest` overrides it with the route coupon's stored `is_automatic`, and its `prepareForValidation()` removes `is_automatic` from input (`$this->request->remove('is_automatic')`) after calling the parent. Rules:
```php
'is_automatic' => ['sometimes', 'boolean'],
'code' => $this->isAutomatic()
    ? ['prohibited']
    : [$this instanceof UpdateCouponRequest ? 'sometimes' : 'required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', $this->uniqueCode()],
'discount_type' => ['required', Rule::enum(CouponDiscountType::class)->when(! $this->isAutomatic(), fn ($rule) => $rule->except(CouponDiscountType::FixedPrice))],
```
If `Rule::enum(...)->except()` is unavailable in the installed Laravel 12 minor (check `composer.lock` version ≥ 11.x has `except`), add the check in the after-hook instead. The after-hook adds, for `fixed_price` on an offer: targets present (sent, else stored on update) and all purposes in one family — families `[[booking, coworking_session], [plan_purchase], [event_registration]]` — else error on `targets` with `api.admin.offer_fixed_price_family`. Keep `PATCH` partial semantics: `discount_type` stays `required` on store only — mirror whatever the existing update test expects (`UpdateCouponRequest` inherits rules today; leave that as-is).

`CouponController`: `index()` adds `if ($request->has('is_automatic')) { $query->where('is_automatic', $request->boolean('is_automatic')); }`; `search` matches `code` or the `name` JSON (`orWhere('name', 'like', ...)`); `store()` forces `per_user_limit` default 1 only for coupons (offers default to `null`); audit payload logs `name` when there is no code. `CouponResource`: `'is_automatic' => $this->is_automatic`.

Lang: `admin.offer_fixed_price_family`: "A fixed price needs targets of one kind: time in a space, plans, or events." / "السعر الثابت يحتاج أهدافاً من نوع واحد: وقت في مساحة، أو باقات، أو فعاليات."

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing/AdminOfferControllerTest.php tests/Feature/Pricing/AdminCouponControllerTest.php` — PASS.
- [ ] **Step 5:** Commit `feat(pricing): offers on the coupon endpoints`.

---

### Task 10: The discount report

**Files:**
- Create: `app/Domain/Pricing/Services/DiscountReport.php`, `app/Http/Controllers/Api/V1/Admin/DiscountReportController.php`, `app/Http/Requests/Admin/DiscountReportRequest.php`, `app/Http/Resources/DiscountReportRowResource.php`
- Modify: `routes/api/v1/admin.php` (next to the coupon reads)
- Test: new `tests/Feature/Pricing/DiscountReportTest.php`

**Interfaces:**
- Consumes: `AppliedDiscount::scopeLive`, `AppliedDiscount::RECORD_COLUMNS`.
- Produces:
  - `DiscountReport::page(array $filters, int $perPage): LengthAwarePaginator` — items are `stdClass` groups `{id, user_id, purpose, booking_id, walkin_session_id, event_attendee_id, payment_id, at}` hydrated by the controller into `['group' => ..., 'record' => Model, 'rows' => Collection<AppliedDiscount>, 'counted' => bool]`.
  - `DiscountReport::totals(array $filters): array{operations: int, list_points: int, final_points: int, points_by_kind: array<string,int>, saving_points: int, effective_percent: int}`.
  - Filters: `from`, `to` (local `Y-m-d`), `kind` (`DiscountKind`), `purpose` (`PricingPurpose`), `status` (`counted|void`).

- [ ] **Step 1: Write the failing tests**
```php
public function test_operations_are_listed_with_every_discount_and_their_status(): void
{
    // booking: list 200, offer 40, coupon 24 → final 136 (spec example); GET admin/reports/discounts?from=…&to=…
    // → data.0.record.type 'booking', record.reference set, list_points 200, final_points 136,
    //   discounts [{kind offer, points 40, percent 20}, {kind coupon, code, points 24, percent 15}], counted true
}

public function test_cancelled_operations_are_listed_but_not_totalled(): void
{
    // two bookings with offers, cancel one → data count 2, one counted false; meta.totals.operations 1
}

public function test_savings_are_totalled_apart_and_never_subtracted_from_list(): void
{
    // yearly plan payment settled with saving 600 and offer 90 → meta.totals.saving_points 600, points_by_kind.offer 90, list_points 900
}

public function test_filters_kind_purpose_status_and_dates(): void
{
    // a booking with a coupon on 2026-10-01, a plan payment with an offer on 2026-10-05
    // ?kind=coupon → only the booking (with both its rows shown); ?purpose=plan_purchase → only the payment;
    // ?status=void → only cancelled; ?from=2026-10-03 → only the payment (dates are local, Asia/Damascus)
}

public function test_the_effective_percent_rounds_down(): void
{
    // list 300, final 200 → 33
}

public function test_operations_staff_can_read_it_and_members_cannot(): void
{
    // operations → 200; member token → 403
}
```
- [ ] **Step 2:** Run — FAIL (404).
- [ ] **Step 3: Implement**

`DiscountReport`:
```php
private function rows(array $filters): Builder
{
    $tz = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

    return AppliedDiscount::query()
        ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', CarbonImmutable::parse($from, $tz)->startOfDay()->utc()))
        ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc()))
        ->when($filters['purpose'] ?? null, fn (Builder $q, string $purpose) => $q->where('purpose', $purpose))
        // All of a record's rows share its liveness, so filtering rows filters records.
        ->when(($filters['status'] ?? null) === 'counted', fn (Builder $q) => $q->live())
        ->when(($filters['status'] ?? null) === 'void', fn (Builder $q) => $q->whereNotIn('id', AppliedDiscount::query()->live()->select('id')))
        ->when($filters['kind'] ?? null, function (Builder $q, string $kind) {
            $withKind = AppliedDiscount::query()->where('kind', $kind);
            $q->where(function (Builder $any) use ($withKind) {
                foreach (AppliedDiscount::RECORD_COLUMNS as $column) {
                    $any->orWhereIn($column, (clone $withKind)->whereNotNull($column)->select($column));
                }
            });
        });
}

public function page(array $filters, int $perPage): LengthAwarePaginator
{
    return $this->rows($filters)
        ->selectRaw('MIN(id) as id, user_id, purpose, booking_id, walkin_session_id, event_attendee_id, payment_id, MIN(created_at) as at')
        ->groupBy('user_id', 'purpose', ...AppliedDiscount::RECORD_COLUMNS)
        ->orderByDesc('at')
        ->paginate($perPage);
}
```
`totals()`: `points_by_kind` = `rows(filters)->live()->whereIn('kind', reductions)->selectRaw('kind, SUM(points) as points')->groupBy('kind')`; `saving_points` the same for `duration_saving`; `operations`, `list_points`, `final_points` by iterating `page()`'s grouped query with `->live()` added (`->cursor()`), loading each record's `list_price_points` and its price (`price_points`, or a payment's `amount_points`) in chunks of 500 per record column (`whereKey` batches); `effective_percent = list > 0 ? intdiv((list − final) * 100, list) : 0`.

Controller `index(DiscountReportRequest $request)`: page → eager-load the page's rows (`AppliedDiscount::whereIn(record col, ids)`), records (`Booking::with('user')`, etc.), and the live ids (`AppliedDiscount::whereKey(page ids)->live()->pluck('id')`); return `DiscountReportRowResource::collection($page)->additional(['meta' => ['totals' => $report->totals($filters)]])`.

`DiscountReportRowResource` emits the spec shape: `record {type: booking|walkin_session|event_attendee|payment, id, reference (booking's `reference`, else null)}`, `member {id, name, phone}`, `purpose`, `at` (ISO), `status` (the record's `status` value; a walk-in, which has none, its `payment_state`), `counted`, `list_points`, `final_points`, `discounts` (the reductions: `kind`, `name` from `terms.name` translated, `code` from `terms.code`, `points`, `percent`), `saving` (`{points, percent, reference_name, units}` or null).

`DiscountReportRequest`: `from`/`to` `nullable|date_format:Y-m-d`, `to` `after_or_equal:from`; `kind` `nullable|Rule::enum(DiscountKind::class)`; `purpose` `nullable|Rule::enum(PricingPurpose::class)`; `status` `nullable|in:counted,void`; `per_page` `nullable|integer|min:1|max:100`.

Route in `admin.php` beside the coupon reads (already behind `role:admin|operations`, E5): `Route::get('reports/discounts', [DiscountReportController::class, 'index']);`. `permissions:sync` will list the controller as uncovered — expected, like the other non-`AdminResourceController` reads.

- [ ] **Step 4:** Run `php artisan test tests/Feature/Pricing/DiscountReportTest.php` — PASS. Spot-check the grouped query on MySQL-strict mode reasoning: every selected non-aggregate column is in `GROUP BY`.
- [ ] **Step 5:** Commit `feat(pricing): a report of every discount and saving given`.

---

### Task 11: Guards, docs, full suite

**Files:**
- Modify: `tests/Guards/PricingIsCentralizedTest.php`, `docs/decisions/pricing-offers-and-coupons.md`, `postman/ADD-OS.postman_collection.json`, `docs/paymera-mobile-api.md` (section 12), `CLAUDE.md` (Payments/pricing paragraph only if it names sales), the spec's **Status** line.

- [ ] **Step 1: Write the failing guard**
```php
public function test_the_discount_ledger_is_written_only_by_the_ledger_and_the_recorder(): void
{
    $this->assertNoneOutside(
        '/AppliedDiscount::(create|query\(\)->(insert|update))|->update\(\[\'(base_points|points)\'/',
        [],
        'applied_discounts is written by CouponLedger and QuoteRecorder only — every reduction and its copied terms in one place:',
    );
}
```
`assertNoneOutside` already exempts `app/Domain/Pricing/`; narrow it for this test so only `Services/CouponLedger.php` and `Services/QuoteRecorder.php` are exempt there (add an optional `$pricingAllowed` list param). Update the class docblock: offers, not sales.

- [ ] **Step 2:** Run `php artisan test tests/Guards` — fix any violation by routing it through `CouponLedger::write()`.
- [ ] **Step 3: Docs**
  - Decision doc: replace the sale section with offers (one engine, best-for-member, E1–E8 table), the ledger table, savings, the report; note phase 1's sale columns were dropped before production.
  - Postman: remove `sale_*` from the space/plan bodies; add an "Offers" folder (create/list/update with `is_automatic`), plan body `reference_*`, `GET admin/reports/discounts` with the query params.
  - Mobile guide §12: `sale` is now per member (send the token), `ends_at`/`ends_label` may be null, the new `saving` block shape.
  - Spec status: "implemented, 2026-10-06".
- [ ] **Step 4:** Run `./vendor/bin/pint` then `php artisan test 2>&1 | tail -60`; diff the failures against `baseline-failures.txt` — no new failures.
- [ ] **Step 5:** Commit `docs(pricing): offers, savings and the discount ledger`.
- [ ] **Step 6:** Dispatch the `code-reviewer` agent over `git diff d8eba17..HEAD` against this plan and the spec; fix confirmed findings in a final commit.
