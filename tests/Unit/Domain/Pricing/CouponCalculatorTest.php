<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Services\CouponCalculator;
use App\Domain\Pricing\ValueObjects\CouponTerms;
use Tests\TestCase;

/**
 * What a coupon takes off a price: a percentage floors (the member never
 * gets more than the percentage strictly grants, as PlanDiscountService
 * rounds), an amount is taken whole, a cap limits either, and nothing ever
 * takes off more than the price itself.
 */
class CouponCalculatorTest extends TestCase
{
    private function discount(CouponDiscountType $type, int $value, int $base, ?int $cap = null): int
    {
        return (new CouponCalculator)->discountFor(new CouponTerms($type, $value, $cap), $base);
    }

    public function test_a_percentage_floors(): void
    {
        $this->assertSame(20, $this->discount(CouponDiscountType::Percentage, 20, 100));
        // 15% of 33 = 4.95 → 4.
        $this->assertSame(4, $this->discount(CouponDiscountType::Percentage, 15, 33));
        $this->assertSame(0, $this->discount(CouponDiscountType::Percentage, 10, 9));
        $this->assertSame(250, $this->discount(CouponDiscountType::Percentage, 100, 250));
    }

    public function test_an_amount_is_taken_whole(): void
    {
        $this->assertSame(30, $this->discount(CouponDiscountType::AmountOff, 30, 100));
    }

    public function test_the_cap_limits_the_discount(): void
    {
        $this->assertSame(25, $this->discount(CouponDiscountType::Percentage, 50, 100, cap: 25));
        $this->assertSame(10, $this->discount(CouponDiscountType::AmountOff, 30, 100, cap: 10));
        $this->assertSame(20, $this->discount(CouponDiscountType::Percentage, 20, 100, cap: 50));
    }

    public function test_never_more_than_the_price(): void
    {
        $this->assertSame(40, $this->discount(CouponDiscountType::AmountOff, 100, 40));
        $this->assertSame(0, $this->discount(CouponDiscountType::AmountOff, 100, 0));
    }
}
