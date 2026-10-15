<?php

namespace App\Http\Requests\Admin;

use App\Domain\Pricing\Models\Coupon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Same shape as creating one; the code may stay its own, and may be left
 * out. Uses already made keep the terms they were made on
 * (AppliedDiscount), so changing the discount only changes what comes
 * next. A coupon stays a coupon and an offer an offer: `is_automatic` is
 * not changed here.
 *
 * A body of `is_active` alone is the on/off switch. Once a coupon or an
 * offer has been used it takes nothing else (409): its uses point back to
 * it, so it is switched off rather than changed — as it is not deleted.
 */
class UpdateCouponRequest extends StoreCouponRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->request->remove('is_automatic');
        $this->json()->remove('is_automatic');

        if ($this->coupon()?->redemptions()->exists() && ! $this->isSwitch()) {
            throw new HttpResponseException(response()->json(['message' => __('api.coupon.used_locked')], 409));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->isSwitch() ? ['is_active' => ['required', 'boolean']] : parent::rules();
    }

    /** Only `is_active` among the coupon's fields — middleware merges others (branch_id). */
    private function isSwitch(): bool
    {
        $fields = array_unique(array_map(fn (string $key) => explode('.', $key)[0], array_keys(parent::rules())));

        return array_keys($this->only($fields)) === ['is_active'];
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
