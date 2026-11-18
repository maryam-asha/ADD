<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The ledger a coupon's limits are counted against
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class CouponLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function ledger(): CouponLedger
    {
        return app(CouponLedger::class);
    }

    public function test_recording_a_use_files_it_on_its_record_with_a_copy_of_the_terms(): void
    {
        $coupon = Coupon::factory()->percentage(20)->create(['max_discount_points' => 50]);
        $member = User::factory()->create();
        $booking = Booking::factory()->create(['user_id' => $member->id]);

        $redemption = $this->ledger()->record($coupon, $member, $booking, PricingPurpose::Booking, 100, 20);

        $this->assertSame($booking->id, $redemption->booking_id);
        $this->assertNull($redemption->payment_id);
        $this->assertSame(CouponDiscountType::Percentage, $redemption->discount_type);
        $this->assertSame(20, $redemption->discount_value);
        $this->assertSame(50, $redemption->max_discount_points);
        $this->assertSame(100, $redemption->base_points);
        $this->assertSame(20, $redemption->points);
        $this->assertTrue($redemption->is($this->ledger()->couponFor($booking)));
    }

    public function test_each_kind_of_record_takes_its_own_column(): void
    {
        $coupon = Coupon::factory()->create();
        $member = User::factory()->create();

        $this->assertNotNull($this->ledger()->record($coupon, $member, WalkinSession::factory()->create(), PricingPurpose::CoworkingSession, 40, 4)->walkin_session_id);
        $this->assertNotNull($this->ledger()->record($coupon, $member, EventAttendee::factory()->create(), PricingPurpose::EventRegistration, 40, 4)->event_attendee_id);
        $this->assertNotNull($this->ledger()->record($coupon, $member, Payment::factory()->create(), PricingPurpose::PlanPurchase, 40, 4)->payment_id);
    }

    /** "The first ten" counts every live use; a member's limit counts theirs. */
    public function test_live_usage_counts_in_total_and_per_member(): void
    {
        $coupon = Coupon::factory()->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $this->ledger()->record($coupon, $alice, Booking::factory()->create(), PricingPurpose::Booking, 100, 10);
        $this->ledger()->record($coupon, $alice, Booking::factory()->create(), PricingPurpose::Booking, 100, 10);
        $cancelled = Booking::factory()->create();
        $this->ledger()->record($coupon, $bob, $cancelled, PricingPurpose::Booking, 100, 10);
        $failed = Payment::factory()->create(['status' => PaymentStatus::Pending]);
        $this->ledger()->record($coupon, $bob, $failed, PricingPurpose::PlanPurchase, 100, 10);

        $this->assertSame(4, $this->ledger()->liveUsage($coupon));
        $this->assertSame(2, $this->ledger()->liveUsage($coupon, $alice));
        $this->assertSame(2, $this->ledger()->liveUsage($coupon, $bob));

        $cancelled->forceFill(['status' => BookingStatus::Cancelled])->save();
        $failed->forceFill(['status' => PaymentStatus::Failed])->save();

        $this->assertSame(2, $this->ledger()->liveUsage($coupon));
        $this->assertSame(0, $this->ledger()->liveUsage($coupon, $bob));
    }

    public function test_locking_returns_the_coupon_read_afresh(): void
    {
        $coupon = Coupon::factory()->create(['usage_limit' => 10]);
        Coupon::query()->whereKey($coupon->id)->update(['usage_limit' => 3]);

        $this->assertSame(3, $this->ledger()->lockForRedemption($coupon)->usage_limit);
    }
}
