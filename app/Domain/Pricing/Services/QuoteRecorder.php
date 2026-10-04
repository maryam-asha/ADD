<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use Illuminate\Database\Eloquent\Model;

/**
 * Locks a quote's uses onto the record it priced, in the same transaction
 * that saved the record: the plan discount's redemption when the plan
 * discount was the reduction chosen (a booking's only), and the coupon's
 * redemption when the coupon was used. Nothing is spent for a sale.
 */
class QuoteRecorder
{
    public function __construct(
        private readonly MembershipResolver $memberships,
        private readonly PlanDiscountService $planDiscounts,
        private readonly CouponLedger $ledger,
    ) {}

    public function record(PriceQuote $quote, Model $record, User $member, PricingPurpose $purpose): void
    {
        $discount = $quote->appliedPlanDiscount();

        if ($discount !== null && $record instanceof Booking && ($membership = $this->memberships->activeFor($member)) !== null) {
            $this->planDiscounts->redeem($discount, $membership, $record, $quote->automaticPoints);
        }

        if (($coupon = $quote->appliedCoupon()) !== null) {
            $this->ledger->record($coupon, $member, $record, $purpose, $quote->couponBasePoints, $quote->couponPoints);
        }
    }
}
