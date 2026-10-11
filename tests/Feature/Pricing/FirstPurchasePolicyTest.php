<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\FirstPurchasePolicy;
use App\Support\Enums\EventPaymentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who a "new members only" coupon is for: someone who has not yet paid
 * for anything. Topping up the wallet is not buying anything, and a
 * membership staff granted was not bought
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md, D4).
 */
class FirstPurchasePolicyTest extends TestCase
{
    use RefreshDatabase;

    private function isNew(User $member): bool
    {
        return app(FirstPurchasePolicy::class)->isFirstPurchase($member);
    }

    public function test_a_member_who_bought_nothing_is_new(): void
    {
        $member = User::factory()->create();

        $this->assertTrue($this->isNew($member));
    }

    public function test_a_top_up_or_a_granted_membership_is_not_a_purchase(): void
    {
        $member = User::factory()->create();
        Payment::factory()->accepted()->create(['user_id' => $member->id, 'purpose' => PaymentPurpose::WalletTopUp, 'settled_at' => now()]);
        Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->assertTrue($this->isNew($member));
    }

    public function test_a_settled_payment_for_something_is_a_purchase(): void
    {
        $member = User::factory()->create();
        Payment::factory()->accepted()->create([
            'user_id' => $member->id,
            'purpose' => PaymentPurpose::PlanPurchase,
            'amount_points' => 500,
            'settled_at' => now(),
        ]);

        $this->assertFalse($this->isNew($member));
    }

    public function test_a_failed_or_refunded_payment_is_not(): void
    {
        $member = User::factory()->create();
        Payment::factory()->create(['user_id' => $member->id, 'purpose' => PaymentPurpose::Booking, 'status' => PaymentStatus::Failed, 'amount_points' => 100]);
        Payment::factory()->accepted()->create([
            'user_id' => $member->id,
            'purpose' => PaymentPurpose::Booking,
            'amount_points' => 100,
            'settled_at' => now(),
            'refunded_at' => now(),
        ]);

        $this->assertTrue($this->isNew($member));
    }

    /** Paid before payment rows existed, or paid at the desk: the record says so. */
    public function test_a_paid_booking_session_or_registration_is_a_purchase(): void
    {
        $booker = User::factory()->create();
        Booking::factory()->create(['user_id' => $booker->id, 'payment_state' => PaymentState::Paid, 'price_points' => 100]);
        $this->assertFalse($this->isNew($booker));

        $walker = User::factory()->create();
        WalkinSession::factory()->create(['user_id' => $walker->id, 'payment_state' => PaymentState::Paid, 'price_points' => 40]);
        $this->assertFalse($this->isNew($walker));

        $attendee = User::factory()->create();
        EventAttendee::factory()->create(['user_id' => $attendee->id, 'payment_state' => EventPaymentState::Paid, 'price_points' => 25]);
        $this->assertFalse($this->isNew($attendee));
    }

    /** Something free, or merely owed, was not bought. */
    public function test_a_free_or_unpaid_record_is_not_a_purchase(): void
    {
        $member = User::factory()->create();
        Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Paid, 'price_points' => 0]);
        Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Unpaid, 'price_points' => 100]);

        $this->assertTrue($this->isNew($member));
    }

    /** One welcome coupon per member: a live use of another one already spent it. */
    public function test_a_live_new_members_only_coupon_use_spends_the_welcome(): void
    {
        $member = User::factory()->create();
        $welcome = Coupon::factory()->create(['new_members_only' => true]);
        $booking = Booking::factory()->create(['user_id' => $member->id, 'status' => BookingStatus::Confirmed]);

        AppliedDiscount::create([
            'coupon_id' => $welcome->id,
            'user_id' => $member->id,
            'purpose' => PricingPurpose::Booking,
            'booking_id' => $booking->id,
            'discount_type' => CouponDiscountType::Percentage,
            'discount_value' => 50,
            'base_points' => 100,
            'points' => 50,
        ]);

        $this->assertFalse($this->isNew($member));

        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        $this->assertTrue($this->isNew($member));
    }
}
