<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use Carbon\CarbonInterface;

/**
 * What a coupon would take off what a member is about to buy — "Apply" in
 * the app, and the price the payment-methods screen weighs the wallet
 * against. The same quote the purchase itself will make, writing nothing:
 * a plan, a booking window, an event, or something already owed.
 */
class CouponPreview
{
    public function __construct(
        private readonly PriceQuoteService $quotes,
        private readonly CouponApplication $application,
    ) {}

    /**
     * @throws CouponRejectedException
     */
    public function plan(User $member, Coupon $coupon, Plan $plan): PriceQuote
    {
        return $this->quotes->forPlan($plan, $member, $coupon, now());
    }

    /**
     * @throws CouponRejectedException
     */
    public function bookingWindow(User $member, Coupon $coupon, Space $space, CarbonInterface $start, CarbonInterface $end): PriceQuote
    {
        return $this->quotes->forBooking($space, $start, $end, $member, $coupon);
    }

    /**
     * @throws CouponRejectedException
     */
    public function event(User $member, Coupon $coupon, Event $event): PriceQuote
    {
        return $this->quotes->forEvent($event, $member, $coupon);
    }

    /**
     * @throws CouponRejectedException
     */
    public function owed(User $member, Coupon $coupon, Booking|WalkinSession|EventAttendee $payable): PriceQuote
    {
        return $this->application->preview($payable, $member, $coupon);
    }
}
