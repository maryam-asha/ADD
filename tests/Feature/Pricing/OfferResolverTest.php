<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\OfferResolver;
use App\Domain\Pricing\ValueObjects\OfferPrice;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which automatic offer prices a purchase: the eligible one giving the
 * lowest price; ineligible ones are dropped silently (E4)
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class OfferResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-06 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolver(): OfferResolver
    {
        return app(OfferResolver::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $targets
     */
    private function offer(array $attributes = [], array $targets = []): Coupon
    {
        $offer = Coupon::factory()->automatic()->create($attributes);

        foreach ($targets as $target) {
            $offer->targets()->create($target);
        }

        return $offer;
    }

    private function room(int $rate): Space
    {
        return Space::factory()->room()->create(['hourly_rate_points' => $rate]);
    }

    private function forSpace(Space $space, string $from, string $to, ?User $member = null): ?OfferPrice
    {
        return $this->resolver()->forSpace($space, Carbon::parse($from), Carbon::parse($to), $member, PricingPurpose::Booking, new UsageInFlight);
    }

    public function test_a_percentage_offer_prices_in_window_time_at_an_exact_rate(): void
    {
        $space = $this->room(15);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

        // 2 h × 13.5, not 2 × 14.
        $this->assertSame(27, $this->forSpace($space, '2026-10-06 09:00', '2026-10-06 11:00')->points);
    }

    public function test_only_the_seconds_inside_the_offer_window_are_discounted(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 70, 'starts_at' => '2026-10-06 10:00:00', 'ends_at' => '2026-10-06 11:00:00'], [['purpose' => 'booking']]);

        $this->assertSame(170, $this->forSpace($space, '2026-10-06 10:00', '2026-10-06 12:00')->points);
    }

    public function test_the_lowest_total_wins_between_overlapping_offers(): void
    {
        $space = $this->room(100);
        $wholeDay = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);
        $oneHour = $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 70, 'starts_at' => '2026-10-06 10:00:00', 'ends_at' => '2026-10-06 11:00:00'], [['purpose' => 'booking']]);

        $twoHours = $this->forSpace($space, '2026-10-06 10:00', '2026-10-06 12:00');
        $this->assertTrue($twoHours->offer->is($wholeDay));
        $this->assertSame(160, $twoHours->points);

        $oneHourBooking = $this->forSpace($space, '2026-10-06 10:00', '2026-10-06 11:00');
        $this->assertTrue($oneHourBooking->offer->is($oneHour));
        $this->assertSame(70, $oneHourBooking->points);
    }

    public function test_a_tie_goes_to_the_offer_ending_first_then_the_lowest_id(): void
    {
        $space = $this->room(100);
        $later = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'ends_at' => '2026-10-20 00:00:00']);
        $sooner = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'ends_at' => '2026-10-10 00:00:00']);
        $openA = $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->assertTrue($this->forSpace($space, '2026-10-06 09:00', '2026-10-06 10:00')->offer->is($sooner));

        $sooner->delete();
        $later->delete();
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 20]);

        $this->assertTrue(app(OfferResolver::class)->forSpace($space, Carbon::parse('2026-10-06 09:00'), Carbon::parse('2026-10-06 10:00'), null, PricingPurpose::Booking, new UsageInFlight)->offer->is($openA));
    }

    public function test_amount_off_takes_points_off_the_hourly_rate(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'amount_off', 'discount_value' => 30]);

        $this->assertSame(140, $this->forSpace($space, '2026-10-06 09:00', '2026-10-06 11:00')->points);
    }

    public function test_the_cap_limits_an_offer_on_time(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 50, 'max_discount_points' => 30]);

        $this->assertSame(170, $this->forSpace($space, '2026-10-06 09:00', '2026-10-06 11:00')->points);
    }

    public function test_a_fixed_price_at_or_above_the_rate_is_ignored(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 100], [['purpose' => 'booking']]);

        $this->assertNull($this->forSpace($space, '2026-10-06 09:00', '2026-10-06 10:00'));
    }

    public function test_ineligible_offers_are_dropped_silently(): void
    {
        $member = User::factory()->create();
        $space = $this->room(100);
        $someoneElses = $this->offer(['discount_type' => 'percentage', 'discount_value' => 50]);
        $someoneElses->users()->attach(User::factory()->create());
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 40, 'is_active' => false]);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 30, 'starts_at' => '2026-11-01 00:00:00']);
        $usedUp = $this->offer(['discount_type' => 'percentage', 'discount_value' => 25, 'usage_limit' => 1]);
        AppliedDiscount::create([
            'kind' => DiscountKind::Offer,
            'coupon_id' => $usedUp->id,
            'user_id' => User::factory()->create()->id,
            'purpose' => PricingPurpose::Booking,
            'booking_id' => Booking::factory()->create(['status' => BookingStatus::Confirmed])->id,
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'base_points' => 100,
            'points' => 25,
        ]);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

        $this->assertSame(90, $this->forSpace($space, '2026-10-06 09:00', '2026-10-06 10:00', $member)->points);
    }

    /** Review Focus 1: no member to check an audience against. */
    public function test_without_a_member_only_offers_for_everyone_apply(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 50, 'new_members_only' => true]);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 40, 'company_id' => Company::factory()->create()->id]);
        $named = $this->offer(['discount_type' => 'percentage', 'discount_value' => 35]);
        $named->users()->attach(User::factory()->create());
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10, 'per_user_limit' => 1]);

        $this->assertSame(90, $this->forSpace($space, '2026-10-06 09:00', '2026-10-06 10:00')->points);
    }

    public function test_an_offer_for_another_target_never_applies(): void
    {
        $space = $this->room(100);
        $plan = Plan::factory()->create(['price_points' => 120]);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 20], [['purpose' => 'plan_purchase', 'plan_id' => $plan->id]]);

        $this->assertNull($this->forSpace($space, '2026-10-06 09:00', '2026-10-06 10:00'));
        $this->assertSame(96, $this->resolver()->forItem(120, PricingTarget::forPlan($plan), null, now(), new UsageInFlight)->points);
    }

    public function test_a_plan_offer_must_be_live_at_the_moment(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120]);
        $this->offer(['discount_type' => 'fixed_price', 'discount_value' => 100, 'ends_at' => '2026-10-07 00:00:00'], [['purpose' => 'plan_purchase']]);

        $this->assertSame(100, $this->resolver()->forItem(120, PricingTarget::forPlan($plan), null, now(), new UsageInFlight)->points);
        $this->assertNull($this->resolver()->forItem(120, PricingTarget::forPlan($plan), null, Carbon::parse('2026-10-07 00:00:00'), new UsageInFlight));
    }

    public function test_an_offer_whose_minimum_is_not_met_does_not_apply(): void
    {
        $plan = Plan::factory()->create(['price_points' => 120]);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 20, 'min_amount_points' => 200]);

        $this->assertNull($this->resolver()->forItem(120, PricingTarget::forPlan($plan), null, now(), new UsageInFlight));
    }

    public function test_uses_earmarked_in_the_batch_count_against_the_limit(): void
    {
        $member = User::factory()->create();
        $space = $this->room(100);
        $offer = $this->offer(['discount_type' => 'percentage', 'discount_value' => 50, 'usage_limit' => 1]);
        $inFlight = new UsageInFlight;
        $inFlight->addCoupon($offer);

        $this->assertNull($this->resolver()->forSpace($space, Carbon::parse('2026-10-06 09:00'), Carbon::parse('2026-10-06 10:00'), $member, PricingPurpose::Booking, $inFlight));
    }

    /** E8: a fraction of a point per hour is shown rounded up. */
    public function test_the_displayed_hourly_rate_rounds_up(): void
    {
        $space = $this->room(15);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

        $this->assertSame(14, $this->resolver()->hourlyNow($space, null)->points);
    }

    /** Review Focus 2. */
    public function test_an_open_ended_offer_has_no_end(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10]);

        $this->assertNull($this->resolver()->hourlyNow($space, null)->endsAt);
    }

    public function test_an_offer_not_live_now_is_not_shown_on_the_rate(): void
    {
        $space = $this->room(100);
        $this->offer(['discount_type' => 'percentage', 'discount_value' => 10, 'starts_at' => '2026-10-06 08:30:00']);

        $this->assertNull($this->resolver()->hourlyNow($space, null));
    }
}
