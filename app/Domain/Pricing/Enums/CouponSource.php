<?php

namespace App\Domain\Pricing\Enums;

/**
 * Who issued a coupon. Phase 1 has only the dashboard; a paid gift code
 * (Phase 2) and a member's referral code (Phase 3) add their own cases, and
 * the admin coupon list keeps showing `admin` ones by default.
 */
enum CouponSource: string
{
    case Admin = 'admin';
}
