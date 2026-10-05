<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\Enums\CouponRejection;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Every reason a code is refused, and every line a sale or coupon adds to
 * a price, reads in both languages — a missing key would show the member
 * the key itself.
 */
class PricingLangKeysTest extends TestCase
{
    /** @return list<string> */
    public static function keys(): array
    {
        return [
            ...array_map(fn (CouponRejection $rejection) => $rejection->messageKey(), CouponRejection::cases()),
            'api.coupon.offer_is_better',
            'api.coupon.has_redemptions',
            'api.booking.sale_label',
            'api.booking.coupon_label',
            'api.space.sale_ends',
            'api.package.sale_ends',
            'api.space.sale_discount_percent',
            'api.space.sale_discount_amount',
            'api.package.sale_discount_percent',
            'api.package.sale_discount_amount',
            'api.admin.sale_not_below_price',
            'api.admin.coupon_updated',
            'api.admin.coupon_target_invalid',
        ];
    }

    public function test_every_pricing_key_exists_in_both_languages(): void
    {
        foreach (['en', 'ar'] as $locale) {
            foreach (self::keys() as $key) {
                $this->assertTrue(Lang::has($key, $locale, false), "Missing lang/{$locale}/api.php {$key}");
            }
        }
    }

    public function test_the_minimum_amount_says_how_much(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->assertStringContainsString('250', __('api.coupon.min_amount_not_met', ['points' => 250], $locale));
        }
    }
}
