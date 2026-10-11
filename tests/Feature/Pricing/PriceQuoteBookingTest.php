<?php

namespace Tests\Feature\Pricing;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponOutcome;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\PriceChoice;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Pricing\ValueObjects\PriceLine;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * The one composition of a booking's price
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md):
 * list → the better of the automatic offer and the plan discount → coupon
 * on top (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 *
 * A room at 100 points an hour, booked for two hours: a list price of 200.
 */
class PriceQuoteBookingTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private Space $room;

    private User $member;

    private CarbonImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-04 08:00:00');

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->member = User::factory()->create();
        $this->start = CarbonImmutable::parse('2026-10-06 10:00:00');
    }

    private function quote(?Coupon $coupon = null, int $hours = 2): PriceQuote
    {
        return app(PriceQuoteService::class)->forBooking($this->room->fresh(), $this->start, $this->start->addHours($hours), $this->member, $coupon);
    }

    /** An automatic offer on the room: a fixed hourly rate over a window. */
    private function sale(int $rate, string $from = '2026-10-05 00:00:00', string $to = '2026-10-12 00:00:00'): Coupon
    {
        return $this->offerOnSpace($this->room, $rate, $from, $to);
    }

    private function planDiscount(int $percent): void
    {
        $plan = Plan::factory()->create();
        $plan->discounts()->create(['percentage' => $percent, 'space_id' => $this->room->id]);
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    /** @return array<string, int> */
    private function lines(PriceQuote $quote): array
    {
        return collect($quote->lines())->mapWithKeys(fn (PriceLine $line) => [$line->key => $line->points])->all();
    }

    public function test_the_list_price_alone(): void
    {
        $quote = $this->quote();

        $this->assertSame(200, $quote->listPoints);
        $this->assertSame(200, $quote->finalPoints);
        $this->assertSame(PriceChoice::List, $quote->choice);
        $this->assertSame(['base' => 200], $this->lines($quote));
        $this->assertSame(CouponOutcome::None, $quote->couponOutcome);
        $this->assertSame(['price_points' => 200, 'list_price_points' => 200], $quote->attributes());
    }

    public function test_a_sale_over_the_whole_booking(): void
    {
        $this->sale(80);

        $quote = $this->quote();

        $this->assertSame(PriceChoice::Offer, $quote->choice);
        $this->assertSame(160, $quote->finalPoints);
        $this->assertSame(['base' => 200, 'sale' => -40], $this->lines($quote));
    }

    public function test_the_quote_carries_the_offer_and_names_its_line(): void
    {
        $offer = $this->sale(80);

        $quote = $this->quote();

        $this->assertTrue($quote->appliedOffer()->is($offer));
        $this->assertSame('Offer', $quote->lines()[1]->name);
    }

    /** Usage time: only the hour inside the sale is at the sale rate. */
    public function test_a_sale_ending_halfway_through_the_booking(): void
    {
        $this->sale(80, to: '2026-10-06 11:00:00');

        $this->assertSame(180, $this->quote()->finalPoints);
    }

    /** The member gets the better of the sale and their plan's discount, never both. */
    public function test_the_better_of_sale_and_plan_discount(): void
    {
        $this->sale(80);
        $this->planDiscount(30);

        $quote = $this->quote();

        $this->assertSame(PriceChoice::Plan, $quote->choice);
        $this->assertSame(140, $quote->finalPoints);
        $this->assertSame(['base' => 200, 'plan_discount' => -60], $this->lines($quote));
        $this->assertNotNull($quote->planDiscount);
    }

    /** A tie goes to the sale: it spends nothing of the plan discount's usage. */
    public function test_a_tie_goes_to_the_sale(): void
    {
        $this->sale(70);
        $this->planDiscount(30);

        $quote = $this->quote();

        $this->assertSame(PriceChoice::Offer, $quote->choice);
        $this->assertSame(140, $quote->finalPoints);
        $this->assertNull($quote->planDiscount);
    }

    public function test_a_coupon_applies_on_top_of_the_sale(): void
    {
        $this->sale(80);
        $coupon = Coupon::factory()->percentage(10)->create();

        $quote = $this->quote($coupon);

        $this->assertSame(CouponOutcome::Applied, $quote->couponOutcome);
        $this->assertSame(160, $quote->couponBasePoints);
        $this->assertSame(16, $quote->couponPoints);
        $this->assertSame(144, $quote->finalPoints);
        $this->assertSame(['base' => 200, 'sale' => -40, 'coupon' => -16], $this->lines($quote));
    }

    /** A coupon that does not combine is weighed against the offer on the list price. */
    public function test_a_non_combinable_coupon_that_beats_the_offer_replaces_it(): void
    {
        $this->sale(80);
        $coupon = Coupon::factory()->percentage(50)->create(['combinable_with_offers' => false]);

        $quote = $this->quote($coupon);

        $this->assertSame(PriceChoice::List, $quote->choice);
        $this->assertSame(CouponOutcome::Applied, $quote->couponOutcome);
        $this->assertSame(100, $quote->finalPoints);
        $this->assertSame(['base' => 200, 'coupon' => -100], $this->lines($quote));
    }

    public function test_a_non_combinable_coupon_that_loses_to_the_offer_is_not_used(): void
    {
        $this->sale(80);
        $coupon = Coupon::factory()->percentage(10)->create(['combinable_with_offers' => false]);

        $quote = $this->quote($coupon);

        $this->assertSame(PriceChoice::Offer, $quote->choice);
        $this->assertSame(CouponOutcome::OfferIsBetter, $quote->couponOutcome);
        $this->assertNull($quote->appliedCoupon());
        $this->assertSame(160, $quote->finalPoints);
    }

    public function test_a_coupon_needs_the_minimum_amount(): void
    {
        $this->sale(80);
        // 160 after the sale is short of 180, though the list price is not.
        $coupon = Coupon::factory()->create(['min_amount_points' => 180]);

        try {
            $this->quote($coupon);
            $this->fail('Expected min_amount_not_met.');
        } catch (CouponRejectedException $e) {
            $this->assertSame(CouponRejection::MinAmountNotMet, $e->rejection);
            $this->assertSame(['points' => 180], $e->params);
        }
    }

    public function test_a_coupon_never_takes_the_price_below_zero(): void
    {
        $coupon = Coupon::factory()->amountOff(500)->create();

        $quote = $this->quote($coupon);

        $this->assertSame(200, $quote->couponPoints);
        $this->assertSame(0, $quote->finalPoints);
    }

    public function test_a_coupon_for_something_else_is_refused(): void
    {
        $coupon = Coupon::factory()->create();
        $coupon->targets()->create(['purpose' => PricingPurpose::PlanPurchase]);

        $this->expectException(CouponRejectedException::class);

        $this->quote($coupon);
    }
}
