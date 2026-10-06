<?php

namespace App\Http\Requests\Admin;

use App\Domain\Pricing\Models\Coupon;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating one; the code may stay its own. Uses already
 * made keep the terms they were made on (AppliedDiscount), so changing
 * the discount only changes what comes next.
 */
class UpdateCouponRequest extends StoreCouponRequest
{
    protected function uniqueCode(): mixed
    {
        $coupon = $this->route('coupon');

        return Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : (int) $coupon);
    }
}
