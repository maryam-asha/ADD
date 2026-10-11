<?php

namespace App\Support\Presenters;

use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PriceQuote;

/**
 * What became of the code a member sent with a purchase, for the response:
 * used (and how much it took off), or not used because the offer already
 * gave a lower price (a coupon that does not combine with offers). A code
 * that could not be used at all never gets here — the purchase was
 * refused with its reason. The exception is paying a record that already
 * exists, where a code that cannot apply (it does not combine with the offer,
 * or a payment is under way) must not stand in the way of paying
 * (notAppliedBecause()).
 */
class CouponOutcomePresenter
{
    /**
     * @return array{code: string, applied: bool, points_discounted: int, message: string|null}
     */
    public function present(string $code, ?AppliedDiscount $redemption, ?string $locale = null): array
    {
        if ($redemption !== null) {
            return [
                'code' => $redemption->coupon->code,
                'applied' => true,
                'points_discounted' => $redemption->points,
                'message' => null,
            ];
        }

        return $this->notApplied($code, $locale);
    }

    /**
     * The same, for a price not yet bought — a preview.
     *
     * @return array{code: string, applied: bool, points_discounted: int, message: string|null}
     */
    public function fromQuote(string $code, PriceQuote $quote, ?string $locale = null): array
    {
        if ($quote->appliedCoupon() === null) {
            return $this->notApplied($code, $locale);
        }

        return [
            'code' => $quote->appliedCoupon()->code,
            'applied' => true,
            'points_discounted' => $quote->couponPoints,
            'message' => null,
        ];
    }

    /**
     * A code that was sent and not used, with the reason in the member's
     * words.
     *
     * @return array{code: string, applied: bool, points_discounted: int, message: string|null}
     */
    public function notAppliedBecause(string $code, string $message): array
    {
        return [
            'code' => Coupon::normalizeCode($code),
            'applied' => false,
            'points_discounted' => 0,
            'message' => $message,
        ];
    }

    /**
     * @return array{code: string, applied: bool, points_discounted: int, message: string|null}
     */
    private function notApplied(string $code, ?string $locale): array
    {
        return $this->notAppliedBecause($code, __('api.coupon.offer_is_better', [], $locale));
    }
}
