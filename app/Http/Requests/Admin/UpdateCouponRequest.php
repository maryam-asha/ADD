<?php

namespace App\Http\Requests\Admin;

use App\Domain\Pricing\Models\Coupon;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating one; the code may stay its own, and may be left
 * out. Uses already made keep the terms they were made on
 * (AppliedDiscount), so changing the discount only changes what comes
 * next. A coupon stays a coupon and an offer an offer: `is_automatic` is
 * not changed here.
 */
class UpdateCouponRequest extends StoreCouponRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->request->remove('is_automatic');
        $this->json()->remove('is_automatic');
    }

    public function isAutomatic(): bool
    {
        return (bool) $this->coupon()?->is_automatic;
    }

    protected function codeRequirement(): string
    {
        return 'sometimes';
    }

    private function coupon(): ?Coupon
    {
        $coupon = $this->route('coupon');

        return $coupon instanceof Coupon ? $coupon : ($coupon !== null ? Coupon::find($coupon) : null);
    }

    protected function uniqueCode(): mixed
    {
        $coupon = $this->route('coupon');

        return Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : (int) $coupon);
    }
}
