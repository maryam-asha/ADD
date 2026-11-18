<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whether a member may use a coupon on what they are buying — one reason
 * per refusal, so the app can say what is wrong
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class CouponEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = User::factory()->create();
        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    }

    private function eligibility(): CouponEligibility
    {
        return app(CouponEligibility::class);
    }

    private function roomBooking(): PricingTarget
    {
        return PricingTarget::forSpace(PricingPurpose::Booking, $this->room);
    }

    private function assertRejected(CouponRejection $expected, callable $check): void
    {
        try {
            $check();
            $this->fail("Expected the coupon to be refused with {$expected->value}.");
        } catch (CouponRejectedException $e) {
            $this->assertSame($expected, $e->rejection);
            $this->assertSame($expected->messageKey(), $e->messageKey);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_a_code_is_found_case_blind(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'WELCOME10']);

        $this->assertTrue($coupon->is($this->eligibility()->resolve('  welcome10 ')));
    }

    /** An unknown and an inactive code answer the same: nothing to probe. */
    public function test_an_unknown_or_inactive_code_is_not_found(): void
    {
        Coupon::factory()->create(['code' => 'PAUSED', 'is_active' => false]);

        $this->assertRejected(CouponRejection::NotFound, fn () => $this->eligibility()->resolve('NOPE'));
        $this->assertRejected(CouponRejection::NotFound, fn () => $this->eligibility()->resolve('paused'));
    }

    public function test_an_unrestricted_coupon_is_usable(): void
    {
        $coupon = Coupon::factory()->create();

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());
        $this->addToAssertionCount(1);
    }

    public function test_a_coupon_before_its_start_or_after_its_end_is_refused(): void
    {
        $early = Coupon::factory()->create(['starts_at' => now()->addDay()]);
        $late = Coupon::factory()->create(['ends_at' => now()->subMinute()]);

        $this->assertRejected(CouponRejection::NotStarted, fn () => $this->eligibility()->assertUsable($early, $this->member, $this->roomBooking()));
        $this->assertRejected(CouponRejection::Expired, fn () => $this->eligibility()->assertUsable($late, $this->member, $this->roomBooking()));
    }

    public function test_a_coupon_applies_only_to_its_targets(): void
    {
        $plan = Plan::factory()->create();
        $event = Event::factory()->create();
        $hall = Space::factory()->create(['space_type' => SpaceType::EventHall]);

        $bookingsOnly = Coupon::factory()->create();
        $bookingsOnly->targets()->create(['purpose' => PricingPurpose::Booking]);

        $roomsOnly = Coupon::factory()->create();
        $roomsOnly->targets()->create(['purpose' => PricingPurpose::Booking, 'space_type' => SpaceType::Room]);

        $thisPlan = Coupon::factory()->create();
        $thisPlan->targets()->create(['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id]);

        $thisEvent = Coupon::factory()->create();
        $thisEvent->targets()->create(['purpose' => PricingPurpose::EventRegistration, 'event_id' => $event->id]);

        $this->eligibility()->assertUsable($bookingsOnly, $this->member, $this->roomBooking());
        $this->eligibility()->assertUsable($roomsOnly, $this->member, $this->roomBooking());
        $this->eligibility()->assertUsable($thisPlan, $this->member, PricingTarget::forPlan($plan));
        $this->eligibility()->assertUsable($thisEvent, $this->member, PricingTarget::forEvent($event));

        $this->assertRejected(CouponRejection::NotApplicable, fn () => $this->eligibility()->assertUsable($bookingsOnly, $this->member, PricingTarget::forPlan($plan)));
        $this->assertRejected(CouponRejection::NotApplicable, fn () => $this->eligibility()->assertUsable($roomsOnly, $this->member, PricingTarget::forSpace(PricingPurpose::Booking, $hall)));
        $this->assertRejected(CouponRejection::NotApplicable, fn () => $this->eligibility()->assertUsable($thisPlan, $this->member, PricingTarget::forPlan(Plan::factory()->create())));
        $this->assertRejected(CouponRejection::NotApplicable, fn () => $this->eligibility()->assertUsable($thisEvent, $this->member, PricingTarget::forEvent(Event::factory()->create())));
    }

    public function test_a_coupon_for_named_members_refuses_anyone_else(): void
    {
        $coupon = Coupon::factory()->create();
        $coupon->users()->attach($this->member);

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());

        $this->assertRejected(CouponRejection::NotEligible, fn () => $this->eligibility()->assertUsable($coupon, User::factory()->create(), $this->roomBooking()));
    }

    public function test_a_company_coupon_is_for_its_members_only(): void
    {
        $company = Company::factory()->create();
        $company->members()->attach($this->member);
        $coupon = Coupon::factory()->create(['company_id' => $company->id]);

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());

        $this->assertRejected(CouponRejection::NotEligible, fn () => $this->eligibility()->assertUsable($coupon, User::factory()->create(), $this->roomBooking()));
    }

    public function test_a_new_members_coupon_refuses_someone_who_already_paid(): void
    {
        $coupon = Coupon::factory()->create(['new_members_only' => true]);

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());

        Payment::factory()->accepted()->create([
            'user_id' => $this->member->id,
            'purpose' => PaymentPurpose::Booking,
            'amount_points' => 100,
            'settled_at' => now(),
        ]);

        $this->assertRejected(CouponRejection::NewMembersOnly, fn () => $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking()));
    }

    public function test_the_first_n_uses_exhaust_the_coupon(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 2]);
        $ledger = app(CouponLedger::class);
        $ledger->record($coupon, User::factory()->create(), Booking::factory()->create(), PricingPurpose::Booking, 100, 10);

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());

        $ledger->record($coupon, User::factory()->create(), Booking::factory()->create(), PricingPurpose::Booking, 100, 10);

        $this->assertRejected(CouponRejection::UsageLimitReached, fn () => $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking()));
    }

    public function test_a_member_cannot_use_it_more_than_their_limit(): void
    {
        $coupon = Coupon::factory()->create(['per_user_limit' => 1]);

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking());

        app(CouponLedger::class)->record($coupon, $this->member, Booking::factory()->create(), PricingPurpose::Booking, 100, 10);

        $this->assertRejected(CouponRejection::PerUserLimitReached, fn () => $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking()));
        $this->eligibility()->assertUsable($coupon, User::factory()->create(), $this->roomBooking());
    }

    /** Several windows of one batch spend the limits before any is saved. */
    public function test_uses_earmarked_in_the_same_batch_count(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 2, 'per_user_limit' => 5]);
        $inFlight = new UsageInFlight;

        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking(), $inFlight);
        $inFlight->addCoupon($coupon);
        $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking(), $inFlight);
        $inFlight->addCoupon($coupon);

        $this->assertRejected(CouponRejection::UsageLimitReached, fn () => $this->eligibility()->assertUsable($coupon, $this->member, $this->roomBooking(), $inFlight));
    }
}
