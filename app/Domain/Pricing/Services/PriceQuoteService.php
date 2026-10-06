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
use App\Domain\Pricing\ValueObjects\OfferPrice;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * The one place a price is composed
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 *
 *   L  the list price                  AmountCalculator (time rounds up once)
 *   O  the best automatic offer price  OfferResolver (or L when none)
 *   P  the member's plan discount on L (bookings only)
 *   A  min(O, P) — never both; a tie takes the offer, which spends no
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
        private readonly MembershipResolver $memberships,
        private readonly PlanDiscountService $planDiscounts,
        private readonly CouponEligibility $eligibility,
        private readonly CouponCalculator $coupons,
        private readonly CouponLedger $ledger,
        private readonly OfferResolver $offers,
    ) {}

    /**
     * A booking of `$space` from `$start` to `$end`. `$member` null (a
     * staff-side preview) gets no plan discount, no coupon, and only an
     * offer for everyone.
     *
     * @throws CouponRejectedException when `$coupon` cannot be used here
     */
    public function forBooking(Space $space, CarbonInterface $start, CarbonInterface $end, ?User $member, ?Coupon $coupon = null, ?UsageInFlight $inFlight = null): PriceQuote
    {
        $inFlight ??= new UsageInFlight;

        $list = $this->amounts->forRange($space, $start, $end);
        $offer = $this->offers->forSpace($space, $start, $end, $member, PricingPurpose::Booking, $inFlight);

        $discount = null;
        $planPrice = $list;
        $membership = $member !== null ? $this->memberships->activeFor($member) : null;

        if ($membership !== null && ($discount = $this->planDiscounts->resolveFor($membership, $space, $inFlight->planDiscounts())) !== null) {
            $planPrice = $this->planDiscounts->apply($list, $discount);
        }

        return $this->compose(
            $list,
            $offer,
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
     * is still active (`$redemption`), else at the offer it was priced with
     * — its copied terms over its copied window, by usage time — else at
     * the list price; never a new offer. Never a
     * coupon — a coupon took its points off the booking as it was made.
     * Each figure is the difference of two whole-booking prices, so the
     * booking stays rounded once, as AmountCalculator rounds.
     *
     * @return array{difference: int, list_difference: int, total: int, undiscounted_total: int}
     */
    public function forExtension(Booking $booking, Space $space, CarbonInterface $newEnd, ?PlanDiscountRedemption $redemption): array
    {
        $oldList = $this->amounts->forRange($space, $booking->start_at, $booking->end_at);
        $newList = $this->amounts->forRange($space, $booking->start_at, $newEnd);

        $offerRow = $redemption === null ? $this->ledger->offerFor($booking) : null;

        [$old, $new] = match (true) {
            $redemption !== null => [$this->planDiscounts->apply($oldList, $redemption->discount), $this->planDiscounts->apply($newList, $redemption->discount)],
            $offerRow !== null => [
                $this->offers->priceSpace($space, $booking->start_at, $booking->end_at, CouponTerms::fromLedger($offerRow)),
                $this->offers->priceSpace($space, $booking->start_at, $newEnd, CouponTerms::fromLedger($offerRow)),
            ],
            default => [$oldList, $newList],
        };

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
     * [`$from`, `$to`). At the terms the record locked: its offer at the
     * copied terms, else a booking's plan discount; and a coupon already on
     * the record, at its copied terms (on the list price when it does not
     * combine with offers). Nothing is re-checked — they were valid when
     * they were locked.
     */
    public function forSession(Booking|WalkinSession $session, CarbonInterface $from, CarbonInterface $to): PriceQuote
    {
        $space = $session->space;
        $offerRow = $this->ledger->offerFor($session);
        $planRedemption = $session instanceof Booking
            ? PlanDiscountRedemption::query()->where('booking_id', $session->id)->first()
            : null;

        // Its locked offer; else, with no plan discount either, the best
        // offer over the time actually used — picked now and locked by the
        // closure (QuoteRecorder::reprice).
        $offer = match (true) {
            $offerRow !== null => new OfferPrice($offerRow->coupon, $this->offers->priceSpace($space, $from, $to, CouponTerms::fromLedger($offerRow)), null),
            $planRedemption !== null => null,
            default => $this->offers->forSpace($space, $from, $to, $session->user, $session instanceof Booking ? PricingPurpose::Booking : PricingPurpose::CoworkingSession, new UsageInFlight),
        };

        return $this->atLockedTerms(
            $this->amounts->forRange($space, $from, $to),
            $offer,
            $offer === null ? $planRedemption?->discount : null,
            $this->ledger->couponFor($session),
        );
    }

    /**
     * A plan bought now, or as of `$asOf`: its list price, the best
     * automatic offer live then, and a coupon on top. Never a plan discount
     * — that is a benefit of holding a plan, not of buying one.
     *
     * @throws CouponRejectedException when `$coupon` cannot be used here
     */
    public function forPlan(Plan $plan, ?User $member, ?Coupon $coupon, CarbonInterface $asOf, ?UsageInFlight $inFlight = null): PriceQuote
    {
        $inFlight ??= new UsageInFlight;
        $list = (int) $plan->price_points;
        $offer = $this->offers->forItem($list, PricingTarget::forPlan($plan), $member, $asOf, $inFlight);

        return $this->compose($list, $offer, null, $list, $member, $coupon, PricingTarget::forPlan($plan), $inFlight);
    }

    /**
     * What a plan payment is due: the plan as it is now — so a plan
     * repriced since is caught — at the offer locked on the payment when it
     * was opened, and the coupon locked on it, both at their copied terms.
     * The payment is honoured as it was quoted, however long the desk or the
     * card takes.
     */
    public function forPlanPayment(Payment $payment, Plan $plan): PriceQuote
    {
        $list = (int) $plan->price_points;
        $offerRow = $payment->exists ? $this->ledger->offerFor($payment) : null;

        $offer = $offerRow !== null
            ? new OfferPrice($offerRow->coupon, $this->offers->priceItem($list, CouponTerms::fromLedger($offerRow)), null)
            : null;

        return $this->atLockedTerms($list, $offer, null, $payment->exists ? $this->ledger->couponFor($payment) : null);
    }

    /**
     * A paid event's registration: its price, the best automatic offer on
     * it, and a coupon on top. A free event takes no coupon.
     *
     * @throws CouponRejectedException
     */
    public function forEvent(Event $event, ?User $member, ?Coupon $coupon): PriceQuote
    {
        $list = ($event->is_free ?? true) ? 0 : (int) ($event->price_points ?? 0);

        if ($coupon !== null && $list <= 0) {
            throw new CouponRejectedException(CouponRejection::NotApplicable);
        }

        $inFlight = new UsageInFlight;
        $offer = $list > 0 ? $this->offers->forItem($list, PricingTarget::forEvent($event), $member, now(), $inFlight) : null;

        return $this->compose($list, $offer, null, $list, $member, $coupon, PricingTarget::forEvent($event), $inFlight);
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
            choice: $offerPriced ? PriceChoice::Offer : PriceChoice::List,
            automaticPoints: $list - $price,
            planDiscount: null,
            coupon: $coupon,
            couponOutcome: CouponOutcome::Applied,
            couponBasePoints: $price,
            couponPoints: $off,
            finalPoints: max(0, $price - $off),
        );
    }

    /**
     * A price at terms already locked on a record: its offer or its plan
     * discount, whichever it was priced with; and its coupon at the copied
     * terms — on the list price when it does not combine with offers.
     * Nothing is re-checked: they were valid when they were locked.
     */
    private function atLockedTerms(int $list, ?OfferPrice $offer, ?PlanDiscount $discount, ?AppliedDiscount $couponRow): PriceQuote
    {
        $onList = $couponRow !== null && ! $couponRow->coupon->combinable_with_offers;

        [$choice, $automatic] = match (true) {
            $onList => [PriceChoice::List, $list],
            $offer !== null => [PriceChoice::Offer, $offer->points],
            $discount !== null => [PriceChoice::Plan, $this->planDiscounts->apply($list, $discount)],
            default => [PriceChoice::List, $list],
        };

        if ($automatic >= $list) {
            [$choice, $automatic] = [PriceChoice::List, $list];
        }

        $couponOff = $couponRow !== null
            ? $this->coupons->discountFor(CouponTerms::fromLedger($couponRow), $automatic)
            : 0;

        return new PriceQuote(
            listPoints: $list,
            choice: $choice,
            automaticPoints: $list - $automatic,
            planDiscount: $choice === PriceChoice::Plan ? $discount : null,
            coupon: $couponRow?->coupon,
            couponOutcome: $couponRow !== null ? CouponOutcome::Applied : CouponOutcome::None,
            couponBasePoints: $couponRow !== null ? $automatic : 0,
            couponPoints: $couponOff,
            finalPoints: max(0, $automatic - $couponOff),
            offer: $choice === PriceChoice::Offer ? $offer?->offer : null,
        );
    }

    /**
     * What a booking was actually charged, read back from what it recorded —
     * its price and list price, its discount-ledger rows — and never
     * re-priced at today's rates, so its breakdown cannot disagree with the
     * charge however the rates, the offers or the coupon have moved since.
     * A booking from before `price_points` existed is priced again from its
     * window, less its plan discount, as its refund always was.
     */
    public function forExistingBooking(Booking $booking): PriceQuote
    {
        $planRedemption = PlanDiscountRedemption::query()->where('booking_id', $booking->id)->first();
        $offerRow = $this->ledger->offerFor($booking);
        $couponRow = $this->ledger->couponFor($booking);

        $planOff = (int) ($planRedemption?->points_discounted ?? 0);
        $couponOff = (int) ($couponRow?->points ?? 0);

        $list = $booking->list_price_points
            ?? ($booking->price_points !== null
                ? $booking->price_points + $planOff + $couponOff
                : $this->amounts->forRange($booking->space, $booking->start_at, $booking->end_at));
        $final = $booking->price_points ?? max(0, $list - $planOff - $couponOff);
        $offerOff = max(0, $list - $final - $planOff - $couponOff);

        [$choice, $automaticOff] = match (true) {
            $planOff > 0 => [PriceChoice::Plan, $planOff],
            $offerOff > 0 => [PriceChoice::Offer, $offerOff],
            default => [PriceChoice::List, 0],
        };

        return new PriceQuote(
            listPoints: $list,
            choice: $choice,
            automaticPoints: $automaticOff,
            planDiscount: $choice === PriceChoice::Plan ? $planRedemption?->discount : null,
            coupon: $couponRow?->coupon,
            couponOutcome: $couponRow !== null ? CouponOutcome::Applied : CouponOutcome::None,
            couponBasePoints: (int) ($couponRow?->base_points ?? 0),
            couponPoints: $couponOff,
            finalPoints: $final,
            offer: $choice === PriceChoice::Offer ? $offerRow?->coupon : null,
        );
    }

    /**
     * @throws CouponRejectedException
     */
    private function compose(int $list, ?OfferPrice $offer, ?PlanDiscount $discount, int $planPrice, ?User $member, ?Coupon $coupon, PricingTarget $target, UsageInFlight $inFlight): PriceQuote
    {
        $offerPrice = $offer?->points ?? $list;

        // The better automatic reduction; a tie takes the offer.
        [$choice, $automatic] = match (true) {
            $offerPrice < $list && $offerPrice <= $planPrice => [PriceChoice::Offer, $offerPrice],
            $discount !== null && $planPrice < $list => [PriceChoice::Plan, $planPrice],
            default => [PriceChoice::List, $list],
        };

        $offerModel = $choice === PriceChoice::Offer ? $offer->offer : null;

        if ($coupon === null || $member === null) {
            return $this->quote($list, $choice, $automatic, $discount, $offerModel, null, CouponOutcome::None, 0, 0);
        }

        $this->eligibility->assertUsable($coupon, $member, $target, $inFlight);
        $terms = CouponTerms::fromCoupon($coupon);

        if ($coupon->combinable_with_offers) {
            $this->assertMinimum($coupon, $automatic);
            $off = $this->coupons->discountFor($terms, $automatic);

            return $this->quote($list, $choice, $automatic, $discount, $offerModel, $coupon, CouponOutcome::Applied, $automatic, $off);
        }

        // Does not combine: the coupon on the list price, if that is lower.
        $this->assertMinimum($coupon, $list);
        $off = $this->coupons->discountFor($terms, $list);

        if ($list - $off < $automatic) {
            return $this->quote($list, PriceChoice::List, $list, $discount, null, $coupon, CouponOutcome::Applied, $list, $off);
        }

        return $this->quote($list, $choice, $automatic, $discount, $offerModel, $coupon, CouponOutcome::OfferIsBetter, 0, 0);
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

    private function quote(int $list, PriceChoice $choice, int $automatic, ?PlanDiscount $discount, ?Coupon $offer, ?Coupon $coupon, CouponOutcome $outcome, int $couponBase, int $couponOff): PriceQuote
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
            offer: $choice === PriceChoice::Offer ? $offer : null,
        );
    }
}
