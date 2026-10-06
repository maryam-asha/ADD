<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use Illuminate\Database\Eloquent\Model;

/**
 * Locks a quote's uses onto the record it priced, in the same transaction
 * that saved the record: the plan discount's redemption when the plan
 * discount was the reduction chosen (a booking's only), and the coupon's
 * when the coupon was used — each also a row of the discount ledger
 * (CouponLedger), with what it took off and the terms it did so on.
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
            $this->ledger->write($record, $member, $purpose, DiscountKind::PlanDiscount, [
                'plan_discount_id' => $discount->id,
                'discount_type' => CouponDiscountType::Percentage,
                'discount_value' => $discount->percentage,
                'base_points' => $quote->listPoints,
                'points' => $quote->automaticPoints,
            ]);
        }

        if (($coupon = $quote->appliedCoupon()) !== null) {
            $this->ledger->record($coupon, $member, $record, $purpose, $quote->couponBasePoints, $quote->couponPoints);
        }
    }

    /**
     * A record priced again — a session closed at the time actually used —
     * keeps its redemptions describing the price it now has, so its
     * breakdown still adds up: what the plan discount and the coupon took
     * off the new price.
     */
    public function reprice(PriceQuote $quote, Model $record): void
    {
        if ($record instanceof Booking && $quote->appliedPlanDiscount() !== null) {
            PlanDiscountRedemption::query()->where('booking_id', $record->id)->update(['points_discounted' => $quote->automaticPoints]);
            $this->ledger->resize($this->ledger->planDiscountFor($record), $quote->listPoints, $quote->automaticPoints);
        }

        if ($quote->appliedCoupon() !== null) {
            $this->ledger->resize($this->ledger->couponFor($record), $quote->couponBasePoints, $quote->couponPoints);
        }
    }
}
