<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanDiscountService;
use App\Domain\Pricing\Enums\CouponOutcome;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\PriceChoice;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\AppliedDiscount;
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
     * What growing a booking to `$newEnd` costs, at the terms it was made on
     * (D7): with the plan discount it was priced with while that membership
     * is still active (`$redemption`), else at the space's sale by usage
     * time. Never a coupon — a coupon took its points off the booking as it
     * was made. Each figure is the difference of two whole-booking prices,
     * so the booking stays rounded once, as AmountCalculator rounds.
     *
     * @return array{difference: int, list_difference: int, total: int, undiscounted_total: int}
     */
    public function forExtension(Booking $booking, Space $space, CarbonInterface $newEnd, ?PlanDiscountRedemption $redemption): array
    {
        $oldList = $this->amounts->forRange($space, $booking->start_at, $booking->end_at);
        $newList = $this->amounts->forRange($space, $booking->start_at, $newEnd);

        if ($redemption !== null) {
            $old = $this->planDiscounts->apply($oldList, $redemption->discount);
            $new = $this->planDiscounts->apply($newList, $redemption->discount);
        } else {
            $old = $this->amounts->forSegments($this->timeline->segments($space, $booking->start_at, $booking->end_at));
            $new = $this->amounts->forSegments($this->timeline->segments($space, $booking->start_at, $newEnd));
        }

        return [
            'difference' => $new - $old,
            'list_difference' => $newList - $oldList,
            'total' => $new,
            'undiscounted_total' => $newList,
        ];
    }

    /**
     * Time used in a space, priced at check-out — a walk-in or co-working
     * session, or a booking left unpaid — over the billed stretch
     * [`$from`, `$to`). At the terms the record locked: a booking's plan
     * discount when it was priced with one, else the sale by usage time;
     * and a coupon already on the record, at its copied terms (on the list
     * price when it does not combine with offers). Nothing is re-checked —
     * the coupon was valid when it was locked.
     */
    public function forSession(Booking|WalkinSession $session, CarbonInterface $from, CarbonInterface $to): PriceQuote
    {
        $space = $session->space;
        $planRedemption = $session instanceof Booking
            ? PlanDiscountRedemption::query()->where('booking_id', $session->id)->first()
            : null;

        return $this->atLockedTerms(
            $this->amounts->forRange($space, $from, $to),
            fn () => $this->amounts->forSegments($this->timeline->segments($space, $from, $to)),
            $planRedemption?->discount,
            $this->ledger->couponFor($session),
        );
    }

    /**
     * A plan bought now, or as of `$asOf`: its list price, its sale when
     * the sale is live then, and a coupon on top. Never a plan discount —
     * that is a benefit of holding a plan, not of buying one.
     *
     * @throws CouponRejectedException when `$coupon` cannot be used here
     */
    public function forPlan(Plan $plan, ?User $member, ?Coupon $coupon, CarbonInterface $asOf, ?UsageInFlight $inFlight = null): PriceQuote
    {
        $list = (int) $plan->price_points;

        return $this->compose($list, $this->planSalePrice($plan, $list, $asOf), null, $list, $member, $coupon, PricingTarget::forPlan($plan), $inFlight ?? new UsageInFlight);
    }

    /**
     * What a plan payment is due: the plan as it is now — so a plan
     * repriced since is caught — at the sale that was live when the payment
     * was opened, and the coupon locked on it at its copied terms. The
     * payment is honoured as it was quoted, however long the desk or the
     * card takes.
     */
    public function forPlanPayment(Payment $payment, Plan $plan): PriceQuote
    {
        $list = (int) $plan->price_points;
        $asOf = $payment->created_at ?? now();

        return $this->atLockedTerms(
            $list,
            fn () => $this->planSalePrice($plan, $list, $asOf),
            null,
            $payment->exists ? $this->ledger->couponFor($payment) : null,
        );
    }

    /**
     * A paid event's registration: its price, and a coupon on top. Events
     * carry no sale of their own. A free event takes no coupon.
     *
     * @throws CouponRejectedException
     */
    public function forEvent(Event $event, ?User $member, ?Coupon $coupon): PriceQuote
    {
        $list = ($event->is_free ?? true) ? 0 : (int) ($event->price_points ?? 0);

        if ($coupon !== null && $list <= 0) {
            throw new CouponRejectedException(CouponRejection::NotApplicable);
        }

        return $this->compose($list, $list, null, $list, $member, $coupon, PricingTarget::forEvent($event), new UsageInFlight);
    }

    /**
     * A coupon on something already priced — what an unpaid booking,
     * session or registration owes: on its price as it stands. Whatever its
     * price is below its list price was an offer, so a coupon that does not
     * combine with offers is refused there.
     *
     * @throws CouponRejectedException
     */
    public function forCouponOn(int $list, int $price, Coupon $coupon, User $member, PricingTarget $target): PriceQuote
    {
        $this->eligibility->assertUsable($coupon, $member, $target);

        $offerPriced = $price < $list;

        if ($offerPriced && ! $coupon->combinable_with_offers) {
            throw new CouponRejectedException(CouponRejection::NotCombinable);
        }

        $this->assertMinimum($coupon, $price);
        $off = $this->coupons->discountFor(CouponTerms::fromCoupon($coupon), $price);

        return new PriceQuote(
            listPoints: $list,
            choice: $offerPriced ? PriceChoice::Sale : PriceChoice::List,
            automaticPoints: $list - $price,
            planDiscount: null,
            coupon: $coupon,
            couponOutcome: CouponOutcome::Applied,
            couponBasePoints: $price,
            couponPoints: $off,
            finalPoints: max(0, $price - $off),
        );
    }

    private function planSalePrice(Plan $plan, int $list, CarbonInterface $asOf): int
    {
        $sale = $plan->saleWindow();

        return $sale !== null && $sale->isLiveAt($asOf) && $sale->points < $list ? $sale->points : $list;
    }

    /**
     * A price at terms already locked on a record: its plan discount when it
     * was priced with one, else the sale; and its coupon at the copied
     * terms — on the list price when it does not combine with offers.
     * Nothing is re-checked: the coupon was valid when it was locked.
     *
     * @param  callable(): int  $salePrice
     */
    private function atLockedTerms(int $list, callable $salePrice, ?PlanDiscount $discount, ?AppliedDiscount $couponRedemption): PriceQuote
    {
        $onList = $couponRedemption !== null && ! $couponRedemption->coupon->combinable_with_offers;

        [$choice, $automatic] = match (true) {
            $onList => [PriceChoice::List, $list],
            $discount !== null => [PriceChoice::Plan, $this->planDiscounts->apply($list, $discount)],
            default => [PriceChoice::Sale, $salePrice()],
        };

        if ($automatic >= $list) {
            [$choice, $automatic] = [PriceChoice::List, $list];
        }

        $couponOff = $couponRedemption !== null
            ? $this->coupons->discountFor(CouponTerms::fromLedger($couponRedemption), $automatic)
            : 0;

        return new PriceQuote(
            listPoints: $list,
            choice: $choice,
            automaticPoints: $list - $automatic,
            planDiscount: $choice === PriceChoice::Plan ? $discount : null,
            coupon: $couponRedemption?->coupon,
            couponOutcome: $couponRedemption !== null ? CouponOutcome::Applied : CouponOutcome::None,
            couponBasePoints: $couponRedemption !== null ? $automatic : 0,
            couponPoints: $couponOff,
            finalPoints: max(0, $automatic - $couponOff),
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
        $couponRedemption = $this->ledger->couponFor($booking);

        $planOff = (int) ($planRedemption?->points_discounted ?? 0);
        $couponOff = (int) ($couponRedemption?->points ?? 0);

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
