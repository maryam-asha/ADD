<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\CouponBesideOffer;
use App\Domain\Pricing\ValueObjects\OfferPrice;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "My coupons": the codes given to this member — by name, or through a
 * company they belong to — that they could use now, and, given what they
 * are about to buy, only those that apply to it. Each is put through the
 * same CouponEligibility the purchase runs, so nothing is listed that the
 * purchase would then refuse (bar, on time in a space, its minimum price,
 * which only a slot's length decides).
 *
 * Asked about an item, each coupon also says how it sits beside the offer
 * live there now (`with_offer`, CouponBesideOffer). A plan or an event is
 * quoted with the coupon as the purchase would quote it — so one whose
 * price is below the coupon's minimum is left out.
 *
 * A code given to no one in particular is never listed: it stays a code
 * handed out outside the app (D11). Offers are not codes, and are shown on
 * the price itself.
 */
class MemberCoupons
{
    public function __construct(
        private readonly CouponEligibility $eligibility,
        private readonly CouponLedger $ledger,
        private readonly OfferResolver $offers,
        private readonly PriceQuoteService $quotes,
    ) {}

    /**
     * @return Collection<int, Coupon> soonest-ending first
     */
    public function for(User $member, ?PricingTarget $target = null, Space|Plan|Event|null $item = null): Collection
    {
        $now = now();
        $offer = $item !== null ? $this->offerOn($item, $member, $target) : null;
        $companyIds = $member->companies()->pluck('companies.id');

        return Coupon::query()
            ->where('is_automatic', false)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->where(fn (Builder $q) => $q
                ->whereHas('users', fn (Builder $u) => $u->whereKey($member->id))
                ->orWhereIn('company_id', $companyIds))
            ->with('targets')
            ->orderByRaw('ends_at IS NULL')
            ->orderBy('ends_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Coupon $coupon) => $this->eligibility->rejection($coupon, $member, $target, at: $now) === null)
            ->each(fn (Coupon $coupon) => $coupon->setAttribute('uses_left', $this->usesLeft($coupon, $member)))
            ->each(fn (Coupon $coupon) => $coupon->setAttribute('with_offer', $item !== null ? $this->besideOffer($coupon, $member, $item, $offer) : null))
            ->reject(fn (Coupon $coupon) => $coupon->getAttribute('with_offer') === false)
            ->values();
    }

    /** The offer live now on the item for this member: the one the `sale` block shows. */
    private function offerOn(Space|Plan|Event $item, User $member, ?PricingTarget $target): ?OfferPrice
    {
        return match (true) {
            $item instanceof Space => $this->offers->hourlyNow($item, $member, $target->purpose),
            $item instanceof Plan => $this->offers->planNow($item, $member),
            default => $this->offers->eventNow($item, $member),
        };
    }

    /**
     * False when the quote refuses the coupon on this item — its price is
     * below the coupon's minimum, or there is no price at all (a free event).
     */
    private function besideOffer(Coupon $coupon, User $member, Space|Plan|Event $item, ?OfferPrice $offer): CouponBesideOffer|false
    {
        if ($item instanceof Space) {
            return new CouponBesideOffer($offer, (int) ($item->hourly_rate_points ?? 0), $coupon->combinable_with_offers, null, null);
        }

        try {
            $quote = $item instanceof Plan
                ? $this->quotes->forPlan($item, $member, $coupon, now())
                : $this->quotes->forEvent($item, $member, $coupon);
        } catch (CouponRejectedException) {
            return false;
        }

        return new CouponBesideOffer($offer, $quote->listPoints, $coupon->combinable_with_offers, $quote->finalPoints, $quote->appliedCoupon() !== null);
    }

    /** How many more times this member may use it: the tighter of its two limits, or null for no limit. */
    public function usesLeft(Coupon $coupon, User $member): ?int
    {
        $left = array_filter([
            $coupon->usage_limit === null ? null : $coupon->usage_limit - $this->ledger->liveUsage($coupon),
            $coupon->per_user_limit === null ? null : $coupon->per_user_limit - $this->ledger->liveUsage($coupon, $member),
        ], fn (?int $n) => $n !== null);

        return $left === [] ? null : min($left);
    }
}
