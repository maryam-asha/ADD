<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `list_price_points` is what a priced record cost before any sale, plan
 * discount or coupon — display only, so a booking's breakdown can be drawn
 * long after the rates have moved. Refunds keep reading payment rows
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
class ListPricePointsTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): object
    {
        return require database_path('migrations/2026_10_05_100700_backfill_list_price_points.php');
    }

    public function test_priced_records_keep_a_list_price(): void
    {
        $booking = Booking::factory()->create(['price_points' => 80, 'list_price_points' => 100]);
        $walkin = WalkinSession::factory()->create(['price_points' => 80, 'list_price_points' => 100]);
        $attendee = EventAttendee::factory()->create(['price_points' => 80, 'list_price_points' => 100]);
        $payment = Payment::factory()->create(['amount_points' => 80, 'list_price_points' => 100]);

        $this->assertSame(100, $booking->fresh()->list_price_points);
        $this->assertSame(100, $walkin->fresh()->list_price_points);
        $this->assertSame(100, $attendee->fresh()->list_price_points);
        $this->assertSame(100, $payment->fresh()->list_price_points);
    }

    /** A booking's list price is its price plus the plan discount it got. */
    public function test_backfill_adds_a_bookings_plan_discount_back(): void
    {
        $discounted = Booking::factory()->create(['price_points' => 70]);
        $plain = Booking::factory()->create(['price_points' => 50]);
        $unpriced = Booking::factory()->create(['price_points' => null]);

        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create(['percentage' => 30]);
        $member = User::factory()->create();
        PlanDiscountRedemption::create([
            'plan_discount_id' => $discount->id,
            'membership_id' => Membership::factory()->create(['plan_id' => $plan->id, 'owner_type' => OwnerType::User, 'owner_id' => $member->id])->id,
            'booking_id' => $discounted->id,
            'points_discounted' => 30,
        ]);

        $this->backfill()->up();

        $this->assertSame(100, $discounted->fresh()->list_price_points);
        $this->assertSame(50, $plain->fresh()->list_price_points);
        $this->assertNull($unpriced->fresh()->list_price_points);
    }

    public function test_backfill_copies_the_price_of_sessions_registrations_and_plan_payments(): void
    {
        $walkin = WalkinSession::factory()->create(['price_points' => 40]);
        $attendee = EventAttendee::factory()->create(['price_points' => 25]);
        $planPayment = Payment::factory()->create(['purpose' => PaymentPurpose::PlanPurchase, 'amount_points' => 500]);
        $topUp = Payment::factory()->create(['purpose' => PaymentPurpose::WalletTopUp, 'amount_points' => 100]);

        $this->backfill()->up();

        $this->assertSame(40, $walkin->fresh()->list_price_points);
        $this->assertSame(25, $attendee->fresh()->list_price_points);
        $this->assertSame(500, $planPayment->fresh()->list_price_points);
        $this->assertNull($topUp->fresh()->list_price_points);
    }
}
