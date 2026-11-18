<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Rules\AppliesToTarget;
use App\Domain\Pricing\Rules\CouponRule;
use App\Domain\Pricing\Rules\IsForNewMembers;
use App\Domain\Pricing\Rules\IsWithinUsageLimits;
use App\Domain\Pricing\Rules\IsWithinValidity;
use App\Domain\Pricing\Rules\MemberIsInAudience;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Domain\Pricing\ValueObjects\UsageInFlight;
use Carbon\CarbonInterface;

/**
 * Whether a member may use a coupon on what they are buying — the one
 * place every entry point asks (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * The rules run in order and the first refusal wins, so a member hears the
 * most basic problem first: an unknown or switched-off code, its dates,
 * what it is for, who it is for, and only then whether it is used up.
 * Whether the price is high enough (`min_amount_points`) is the quote's to
 * decide, since only it knows the price.
 */
class CouponEligibility
{
    /** @var list<CouponRule> */
    private readonly array $rules;

    public function __construct(
        IsWithinValidity $validity,
        AppliesToTarget $target,
        MemberIsInAudience $audience,
        IsForNewMembers $newMembers,
        IsWithinUsageLimits $limits,
    ) {
        $this->rules = [$validity, $target, $audience, $newMembers, $limits];
    }

    /**
     * The active coupon a typed code names, matched case-blind.
     *
     * @throws CouponRejectedException not_found, for an unknown or switched-off code alike
     */
    public function resolve(string $code): Coupon
    {
        return Coupon::query()
            ->where('is_automatic', false)
            ->where('code', Coupon::normalizeCode($code))
            ->where('is_active', true)
            ->first()
            ?? throw new CouponRejectedException(CouponRejection::NotFound);
    }

    /**
     * @throws CouponRejectedException with the first rule that refuses
     */
    public function assertUsable(Coupon $coupon, User $member, PricingTarget $target, ?UsageInFlight $inFlight = null, ?CarbonInterface $at = null): void
    {
        if (($rejection = $this->rejection($coupon, $member, $target, $inFlight, $at)) !== null) {
            throw new CouponRejectedException($rejection);
        }
    }

    /**
     * The first rule that refuses, or null. With no target, what the coupon
     * is for is not asked — only whether this member may use it at all.
     */
    public function rejection(Coupon $coupon, User $member, ?PricingTarget $target, ?UsageInFlight $inFlight = null, ?CarbonInterface $at = null): ?CouponRejection
    {
        $inFlight ??= new UsageInFlight;
        $at ??= now();

        foreach ($this->rules as $rule) {
            if (($rejection = $rule->rejection($coupon, $member, $target, $inFlight, $at)) !== null) {
                return $rejection;
            }
        }

        return null;
    }
}
