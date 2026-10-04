<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Pricing\Enums\CouponOutcome;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\PriceChoice;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\CouponTerms;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * The one place a price is composed
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md):
 *
 *   L  the list price
 *   S  the sale price (a space's by usage time, a plan's as of a moment)
 *   P  the member's plan discount on L (bookings only)
 *   A  min(S, P) — never both; a tie takes the sale, which spends no
 *      plan-discount usage
 *   then a coupon on A, or — when it does not combine with offers — on L
 *   instead, if that comes out lower; otherwise it is not used.
 *
 * Integer points throughout: time prices round up once
 * (AmountCalculator), discounts round down (PlanDiscountService,
 * CouponCalculator). Every caller that charges, previews or re-prices goes
 * through here, so the screen and the charge cannot disagree
 * (tests/Guards/PricingIsCentralizedTest).
 */
class PriceQuoteService
{
    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly SpaceRateTimeline $timeline,
        private readonly MembershipResolver $memberships,
        private readonly PlanDiscountService $planDiscounts,
        private readonly CouponEligibility $eligibility,
        private readonly CouponCalculator $coupons,
        private readonly CouponLedger $ledger,
    ) {}

    /**
     * A booking of `$space` from `$start` to `$end`. `$member` null (a
     * staff-side preview) gets neither a plan discount nor a coupon.
     *
     * @throws CouponRejectedException when `$coupon` cannot be used here
     */
    public function forBooking(Space $space, CarbonInterface $start, CarbonInterface $end, ?User $member, ?Coupon $coupon = null, ?UsageInFlight $inFlight = null): PriceQuote
    {
        $inFlight ??= new UsageInFlight;

        $list = $this->amounts->forRange($space, $start, $end);
        $sale = $this->amounts->forSegments($this->timeline->segments($space, $start, $end));

        $discount = null;
        $planPrice = $list;
        $membership = $member !== null ? $this->memberships->activeFor($member) : null;

        if ($membership !== null && ($discount = $this->planDiscounts->resolveFor($membership, $space, $inFlight->planDiscounts())) !== null) {
            $planPrice = $this->planDiscounts->apply($list, $discount);
        }

        return $this->compose(
            $list,
            $sale,
            $discount,
            $planPrice,
            $member,
            $coupon,
            PricingTarget::forSpace(PricingPurpose::Booking, $space),
            $inFlight,
        );
    }

    /**
     * What a booking was actually charged, read back from what it recorded —
     * its price and list price, its plan-discount and coupon redemptions —
     * and never re-priced at today's rates, so its breakdown cannot disagree
     * with the charge however the rates, the sale or the coupon have moved
     * since. Whatever is left of the list price besides those two was the
     * sale. A booking from before `price_points` existed is priced again
     * from its window, less its plan discount, as its refund always was.
     */
    public function forExistingBooking(Booking $booking): PriceQuote
    {
        $planRedemption = PlanDiscountRedemption::query()->where('booking_id', $booking->id)->first();
        $couponRedemption = $this->ledger->forRedeemable($booking);

        $planOff = (int) ($planRedemption?->points_discounted ?? 0);
        $couponOff = (int) ($couponRedemption?->points_discounted ?? 0);

        $list = $booking->list_price_points
            ?? ($booking->price_points !== null
                ? $booking->price_points + $planOff + $couponOff
                : $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at));
        $final = $booking->price_points ?? max(0, $list - $planOff - $couponOff);
        $saleOff = max(0, $list - $final - $planOff - $couponOff);

        [$choice, $automaticOff] = match (true) {
            $planOff > 0 => [PriceChoice::Plan, $planOff],
            $saleOff > 0 => [PriceChoice::Sale, $saleOff],
            default => [PriceChoice::List, 0],
        };

        return new PriceQuote(
            listPoints: $list,
            choice: $choice,
            automaticPoints: $automaticOff,
            planDiscount: $choice === PriceChoice::Plan ? $planRedemption?->discount : null,
            coupon: $couponRedemption?->coupon,
            couponOutcome: $couponRedemption !== null ? CouponOutcome::Applied : CouponOutcome::None,
            couponBasePoints: (int) ($couponRedemption?->base_points ?? 0),
            couponPoints: $couponOff,
            finalPoints: $final,
        );
    }

    /**
     * @throws CouponRejectedException
     */
    private function compose(int $list, int $sale, ?PlanDiscount $discount, int $planPrice, ?User $member, ?Coupon $coupon, PricingTarget $target, UsageInFlight $inFlight): PriceQuote
    {
        // The better automatic reduction; a tie takes the sale.
        [$choice, $automatic] = match (true) {
            $sale < $list && $sale <= $planPrice => [PriceChoice::Sale, $sale],
            $discount !== null && $planPrice < $list => [PriceChoice::Plan, $planPrice],
            default => [PriceChoice::List, $list],
        };

        if ($coupon === null || $member === null) {
            return $this->quote($list, $choice, $automatic, $discount, null, CouponOutcome::None, 0, 0);
        }

        $this->eligibility->assertUsable($coupon, $member, $target, $inFlight);
        $terms = CouponTerms::fromCoupon($coupon);

        if ($coupon->combinable_with_offers) {
            $this->assertMinimum($coupon, $automatic);
            $off = $this->coupons->discountFor($terms, $automatic);

            return $this->quote($list, $choice, $automatic, $discount, $coupon, CouponOutcome::Applied, $automatic, $off);
        }

        // Does not combine: the coupon on the list price, if that is lower.
        $this->assertMinimum($coupon, $list);
        $off = $this->coupons->discountFor($terms, $list);

        if ($list - $off < $automatic) {
            return $this->quote($list, PriceChoice::List, $list, $discount, $coupon, CouponOutcome::Applied, $list, $off);
        }

        return $this->quote($list, $choice, $automatic, $discount, $coupon, CouponOutcome::OfferIsBetter, 0, 0);
    }

    /**
     * @throws CouponRejectedException
     */
    private function assertMinimum(Coupon $coupon, int $base): void
    {
        if ($coupon->min_amount_points !== null && $base < $coupon->min_amount_points) {
            throw new CouponRejectedException(CouponRejection::MinAmountNotMet, ['points' => $coupon->min_amount_points]);
        }
    }

    private function quote(int $list, PriceChoice $choice, int $automatic, ?PlanDiscount $discount, ?Coupon $coupon, CouponOutcome $outcome, int $couponBase, int $couponOff): PriceQuote
    {
        return new PriceQuote(
            listPoints: $list,
            choice: $choice,
            automaticPoints: $list - $automatic,
            planDiscount: $choice === PriceChoice::Plan ? $discount : null,
            coupon: $coupon,
            couponOutcome: $outcome,
            couponBasePoints: $couponBase,
            couponPoints: $couponOff,
            finalPoints: max(0, $automatic - $couponOff),
        );
    }
}
