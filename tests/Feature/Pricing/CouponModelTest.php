<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md
 */
class CouponModelTest extends TestCase
{
    use RefreshDatabase;

    /** Codes are matched case-blind: stored trimmed and upper-cased. */
    public function test_a_code_is_stored_upper_cased(): void
    {
        $coupon = Coupon::factory()->create(['code' => '  welcome10 ']);

        $this->assertSame('WELCOME10', $coupon->fresh()->code);
    }

    public function test_a_percentage_above_one_hundred_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Coupon::factory()->create(['discount_type' => CouponDiscountType::Percentage, 'discount_value' => 101]);
    }

    public function test_a_discount_of_nothing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Coupon::factory()->create(['discount_type' => CouponDiscountType::AmountOff, 'discount_value' => 0]);
    }

    public function test_a_coupon_with_no_targets_applies_to_everything(): void
    {
        $coupon = Coupon::factory()->create();

        $this->assertTrue($coupon->appliesToEverything());

        $coupon->targets()->create(['purpose' => PricingPurpose::Booking]);

        $this->assertFalse($coupon->fresh()->appliesToEverything());
    }

    /** One item at most, and an item of the purpose's own kind. */
    public function test_a_target_names_at_most_one_item_of_its_purpose(): void
    {
        $coupon = Coupon::factory()->create();
        $plan = Plan::factory()->create();

        $coupon->targets()->create(['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id]);
        $coupon->targets()->create(['purpose' => PricingPurpose::Booking, 'space_type' => SpaceType::Room]);
        $this->assertSame(2, $coupon->targets()->count());

        $this->assertThrows(
            fn () => $coupon->targets()->create(['purpose' => PricingPurpose::Booking, 'plan_id' => $plan->id]),
            InvalidArgumentException::class,
        );

        $this->assertThrows(
            fn () => $coupon->targets()->create(['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id, 'space_type' => SpaceType::Room]),
            InvalidArgumentException::class,
        );
    }

    public function test_a_redemption_belongs_to_exactly_one_priced_record(): void
    {
        $coupon = Coupon::factory()->create();
        $booking = Booking::factory()->create();
        $walkin = WalkinSession::factory()->create();

        $this->assertThrows(fn () => $this->redemption($coupon, []), InvalidArgumentException::class);
        $this->assertThrows(
            fn () => $this->redemption($coupon, ['booking_id' => $booking->id, 'walkin_session_id' => $walkin->id]),
            InvalidArgumentException::class,
        );
    }

    /**
     * A use counts while what it is attached to can still be (or was)
     * bought: a booking holding its slot, a registration holding its seat,
     * a payment that may yet be — or was — paid, any walk-in. Cancelling,
     * rejecting, failing, expiring or reversing gives it back.
     */
    public function test_live_counts_only_uses_whose_record_still_holds_them(): void
    {
        $coupon = Coupon::factory()->create();

        $this->redemption($coupon, ['booking_id' => Booking::factory()->create(['status' => BookingStatus::Confirmed])->id]);
        $this->redemption($coupon, ['booking_id' => Booking::factory()->create(['status' => BookingStatus::NoShow])->id]);
        $this->redemption($coupon, ['booking_id' => Booking::factory()->create(['status' => BookingStatus::Cancelled])->id]);
        $this->redemption($coupon, ['booking_id' => Booking::factory()->create(['status' => BookingStatus::Rejected])->id]);
        $this->redemption($coupon, ['payment_id' => Payment::factory()->create(['status' => PaymentStatus::Pending])->id]);
        $this->redemption($coupon, ['payment_id' => Payment::factory()->create(['status' => PaymentStatus::Accepted])->id]);
        $this->redemption($coupon, ['payment_id' => Payment::factory()->create(['status' => PaymentStatus::Failed])->id]);
        $this->redemption($coupon, ['payment_id' => Payment::factory()->create(['status' => PaymentStatus::Expired])->id]);
        $this->redemption($coupon, ['payment_id' => Payment::factory()->create(['status' => PaymentStatus::Reversed])->id]);
        $this->redemption($coupon, ['event_attendee_id' => EventAttendee::factory()->create(['status' => EventRegistrationStatus::Approved])->id]);
        $this->redemption($coupon, ['event_attendee_id' => EventAttendee::factory()->create(['status' => EventRegistrationStatus::Rejected])->id]);
        $this->redemption($coupon, ['walkin_session_id' => WalkinSession::factory()->create()->id]);

        // confirmed, no_show, pending, accepted, approved, walk-in.
        $this->assertSame(6, $coupon->redemptions()->live()->count());
    }

    private function redemption(Coupon $coupon, array $attachment): AppliedDiscount
    {
        return AppliedDiscount::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
            'purpose' => PricingPurpose::Booking,
            'discount_type' => CouponDiscountType::Percentage,
            'discount_value' => 10,
            'base_points' => 100,
            'points' => 10,
        ], $attachment));
    }

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
        Coupon::factory()->automatic()->create();

        $this->expectException(CouponRejectedException::class);

        app(CouponEligibility::class)->resolve('');
    }

    public function test_a_fixed_price_is_for_offers_only(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Coupon::factory()->fixedPrice(100)->create();
    }
}
