<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Rules\AppliesToTarget;
use App\Domain\Pricing\Rules\CouponRule;
use App\Domain\Pricing\Rules\IsForNewMembers;
use App\Domain\Pricing\Rules\IsWithinUsageLimits;
use App\Domain\Pricing\Rules\MemberIsInAudience;
use App\Domain\Pricing\ValueObjects\CouponTerms;
use App\Domain\Pricing\ValueObjects\OfferPrice;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Which automatic offer prices a purchase, and at what price
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 * of the active offers for this target that this member may use — the
 * audience, new-members and usage rules a typed coupon meets — the one
 * giving the lowest price. A tie goes to the offer ending first, then the
 * lowest id. An offer that cannot apply is dropped silently (E4): rejection
 * messages are for typed codes.
 *
 * Time in a space is priced by usage time over the offer's window (E3); a
 * plan or an event at a moment, when the offer is live then. One offer per
 * purchase, never mixed segment by segment (E2).
 */
class OfferResolver
{
    /** @var list<CouponRule> who may use an offer — everything but its target */
    private readonly array $memberRules;

    /**
     * What the app shows (hourlyNow, planNow) is read once per instance — a
     * listing shows many cards, and who may use an offer does not depend on
     * the card. A price that is charged reads afresh every time, so a limit
     * is counted from the rows its transaction locked.
     */
    private ?Collection $shown = null;

    /** @var array<int, array<int, bool>> member id (0: none) => offer id => usable */
    private array $usable = [];

    public function __construct(
        private readonly AmountCalculator $amounts,
        private readonly SpaceRateTimeline $timeline,
        private readonly CouponCalculator $calculator,
        private readonly CouponLedger $ledger,
        private readonly AppliesToTarget $appliesTo,
        MemberIsInAudience $audience,
        IsForNewMembers $newMembers,
        IsWithinUsageLimits $limits,
    ) {
        $this->memberRules = [$audience, $newMembers, $limits];
    }

    /** The best offer on [$from, $to) in a space, priced over the whole range. */
    public function forSpace(Space $space, CarbonInterface $from, CarbonInterface $to, ?User $member, PricingPurpose $purpose, UsageInFlight $inFlight): ?OfferPrice
    {
        return $this->best(
            $this->candidates(PricingTarget::forSpace($purpose, $space), $member, $inFlight, shown: false)
                ->filter(fn (Coupon $offer) => $offer->window()->overlaps($from, $to)),
            $this->amounts->forRange($space, $from, $to),
            fn (CouponTerms $terms) => $this->priceSpace($space, $from, $to, $terms),
        );
    }

    /** The best offer on a plan or an event priced `$list`, live at `$at`. */
    public function forItem(int $list, PricingTarget $target, ?User $member, CarbonInterface $at, UsageInFlight $inFlight, bool $shown = false): ?OfferPrice
    {
        return $this->best(
            $this->candidates($target, $member, $inFlight, $shown)->filter(fn (Coupon $offer) => $offer->window()->isLiveAt($at)),
            $list,
            fn (CouponTerms $terms) => $this->priceItem($list, $terms),
        );
    }

    /**
     * [$from, $to) in a space at an offer's terms — fresh or copied onto a
     * record — rounded up once, never more off than `max_discount_points`.
     */
    public function priceSpace(Space $space, CarbonInterface $from, CarbonInterface $to, CouponTerms $terms): int
    {
        $list = $this->amounts->forRange($space, $from, $to);
        $price = $this->amounts->forSegments($this->timeline->segments($space, $from, $to, $terms));

        return $terms->maxDiscountPoints !== null ? max($price, $list - $terms->maxDiscountPoints) : $price;
    }

    /** A plan's or an event's price at an offer's terms, as a coupon takes it off. */
    public function priceItem(int $list, CouponTerms $terms): int
    {
        return $list - $this->calculator->discountFor($terms, $list);
    }

    /**
     * The offer on a space's hourly rate live now for this member, as the
     * app shows it: the rate rounded UP when a percentage leaves a fraction
     * of a point (E8) — it never understates the price; the charge is exact.
     */
    public function hourlyNow(Space $space, ?User $member): ?OfferPrice
    {
        $rate = (int) ($space->hourly_rate_points ?? 0);

        if ($rate <= 0) {
            return null;
        }

        $best = $this->best(
            $this->candidates(PricingTarget::forSpace(PricingPurpose::Booking, $space), $member, new UsageInFlight, shown: true)
                ->filter(fn (Coupon $offer) => $offer->window()->isLiveAt(now())),
            $rate,
            fn (CouponTerms $terms) => $this->roundedUp($this->timeline->offerRate($rate, $terms) ?? (string) $rate),
        );

        return $best;
    }

    /** The offer on a plan live now for this member: what a purchase opened now would cost. */
    public function planNow(Plan $plan, ?User $member): ?OfferPrice
    {
        return $this->forItem((int) $plan->price_points, PricingTarget::forPlan($plan), $member, now(), new UsageInFlight, shown: true);
    }

    /**
     * The offers for this target this member may use. The target is checked
     * first — in memory, from the loaded targets; AppliesToTarget never
     * reads the member, so a blank one stands in when there is none.
     *
     * @return BaseCollection<int, Coupon>
     */
    private function candidates(PricingTarget $target, ?User $member, UsageInFlight $inFlight, bool $shown): BaseCollection
    {
        $offers = $shown ? ($this->shown ??= $this->activeOffers()) : $this->activeOffers();

        return $offers
            ->filter(fn (Coupon $offer) => $this->appliesTo->rejection($offer, $member ?? new User, $target, $inFlight, now()) === null)
            ->filter(fn (Coupon $offer) => $shown
                ? $this->usable[$member->id ?? 0][$offer->id] ??= $this->usableBy($offer, $member, $inFlight)
                : $this->usableBy($offer, $member, $inFlight));
    }

    /** @return Collection<int, Coupon> */
    private function activeOffers(): Collection
    {
        return Coupon::query()->automatic()->where('is_active', true)->with('targets')->withCount('users')->orderBy('id')->get();
    }

    /**
     * Whether `$member` may use the offer: the audience, new-members and
     * usage rules a typed coupon meets. With no member to check an audience
     * against — an anonymous listing, a staff-side preview — only an offer
     * for everyone, within its total limit.
     */
    private function usableBy(Coupon $offer, ?User $member, UsageInFlight $inFlight): bool
    {
        if ($member === null) {
            return $offer->company_id === null
                && ! $offer->new_members_only
                && (int) $offer->users_count === 0
                && ($offer->usage_limit === null || $this->ledger->liveUsage($offer) + $inFlight->coupon($offer) < $offer->usage_limit);
        }

        return collect($this->memberRules)->every(fn (CouponRule $rule) => $rule->rejection($offer, $member, new PricingTarget(PricingPurpose::Booking), $inFlight, now()) === null);
    }

    /**
     * @param  BaseCollection<int, Coupon>  $offers
     * @param  callable(CouponTerms): int  $price
     */
    private function best(BaseCollection $offers, int $list, callable $price): ?OfferPrice
    {
        return $offers
            ->reject(fn (Coupon $offer) => $offer->min_amount_points !== null && $list < $offer->min_amount_points)
            ->map(fn (Coupon $offer) => new OfferPrice($offer, $price(CouponTerms::fromCoupon($offer)), $offer->window()->endsAt))
            ->filter(fn (OfferPrice $candidate) => $candidate->points < $list)
            ->sort(fn (OfferPrice $a, OfferPrice $b) => [$a->points, $a->endsAt?->getTimestamp() ?? PHP_INT_MAX, $a->offer->id]
                <=> [$b->points, $b->endsAt?->getTimestamp() ?? PHP_INT_MAX, $b->offer->id])
            ->first();
    }

    private function roundedUp(string $exact): int
    {
        $whole = bcdiv($exact, '1', 0);

        return (int) (bccomp($exact, $whole, 2) > 0 ? bcadd($whole, '1') : $whole);
    }
}
