<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponOutcome;
use App\Domain\Pricing\Enums\PriceChoice;
use App\Domain\Pricing\Models\Coupon;

/**
 * A composed price: the list price, the automatic reduction chosen (sale or
 * plan discount, never both), the coupon on top, and what is left to pay.
 * Only PriceQuoteService makes one
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
final class PriceQuote
{
    public function __construct(
        public readonly int $listPoints,
        public readonly PriceChoice $choice,
        public readonly int $automaticPoints,
        public readonly ?PlanDiscount $planDiscount,
        public readonly ?Coupon $coupon,
        public readonly CouponOutcome $couponOutcome,
        public readonly int $couponBasePoints,
        public readonly int $couponPoints,
        public readonly int $finalPoints,
    ) {}

    /** The price after the automatic reduction, before any coupon. */
    public function automaticPrice(): int
    {
        return $this->listPoints - $this->automaticPoints;
    }

    /** The coupon, when it was actually used. */
    public function appliedCoupon(): ?Coupon
    {
        return $this->couponOutcome === CouponOutcome::Applied ? $this->coupon : null;
    }

    /** The plan discount, when it was the reduction chosen. */
    public function appliedPlanDiscount(): ?PlanDiscount
    {
        return $this->choice === PriceChoice::Plan ? $this->planDiscount : null;
    }

    /** @return list<PriceLine> */
    public function lines(): array
    {
        $lines = [new PriceLine('base', $this->listPoints)];

        if ($this->automaticPoints > 0 && $this->choice === PriceChoice::Sale) {
            $lines[] = new PriceLine('sale', -$this->automaticPoints);
        } elseif ($this->automaticPoints > 0 && $this->choice === PriceChoice::Plan) {
            $lines[] = new PriceLine('plan_discount', -$this->automaticPoints, percentage: $this->planDiscount?->percentage);
        }

        $coupon = $this->appliedCoupon();

        if ($coupon !== null && $this->couponPoints > 0) {
            $lines[] = new PriceLine(
                'coupon',
                -$this->couponPoints,
                percentage: $coupon->discount_type === CouponDiscountType::Percentage ? $coupon->discount_value : null,
                code: $coupon->code,
            );
        }

        return $lines;
    }

    /**
     * The record's price and list price, written together so they never
     * disagree. A booking or walk-in adds its USD `amount_owed` alongside.
     *
     * @return array{price_points: int, list_price_points: int}
     */
    public function attributes(): array
    {
        return ['price_points' => $this->finalPoints, 'list_price_points' => $this->listPoints];
    }

    /** Earmarks this quote's uses for the next window of the same batch. */
    public function earmark(UsageInFlight $inFlight): void
    {
        if (($discount = $this->appliedPlanDiscount()) !== null) {
            $inFlight->addPlanDiscount($discount);
        }

        if (($coupon = $this->appliedCoupon()) !== null) {
            $inFlight->addCoupon($coupon);
        }
    }
}
