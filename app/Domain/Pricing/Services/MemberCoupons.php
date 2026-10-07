<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "My coupons": the codes given to this member — by name, or through a
 * company they belong to — that they could use now, and, given what they
 * are about to buy, only those that apply to it. Each is put through the
 * same CouponEligibility the purchase runs, so nothing is listed that the
 * purchase would then refuse (bar its minimum price, which only the quote
 * knows).
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
    ) {}

    /**
     * @return Collection<int, Coupon> soonest-ending first
     */
    public function for(User $member, ?PricingTarget $target = null): Collection
    {
        $now = now();
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
            ->values();
    }

    /** How many more times this member may use it: the tighter of its two limits, or null for no limit. */
    private function usesLeft(Coupon $coupon, User $member): ?int
    {
        $left = array_filter([
            $coupon->usage_limit === null ? null : $coupon->usage_limit - $this->ledger->liveUsage($coupon),
            $coupon->per_user_limit === null ? null : $coupon->per_user_limit - $this->ledger->liveUsage($coupon, $member),
        ], fn (?int $n) => $n !== null);

        return $left === [] ? null : min($left);
    }
}
