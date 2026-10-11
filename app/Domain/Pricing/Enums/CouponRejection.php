<?php

namespace App\Domain\Pricing\Enums;

/**
 * Why a coupon code cannot be used, each with its own message
 * (`api.coupon.<value>`), so the app can tell the member what is wrong.
 *
 * An inactive code answers `not_found`, exactly like one that never
 * existed, so codes cannot be probed for.
 */
enum CouponRejection: string
{
    case NotFound = 'not_found';
    case NotStarted = 'not_started';
    case Expired = 'expired';
    case UsageLimitReached = 'usage_limit_reached';
    case PerUserLimitReached = 'per_user_limit_reached';
    case NotEligible = 'not_eligible';
    case NewMembersOnly = 'new_members_only';
    case NotApplicable = 'not_applicable';
    case MinAmountNotMet = 'min_amount_not_met';
    case NotCombinable = 'not_combinable';
    case AlreadyApplied = 'already_applied';
    case PaymentInProgress = 'payment_in_progress';

    public function messageKey(): string
    {
        return 'api.coupon.'.$this->value;
    }
}
