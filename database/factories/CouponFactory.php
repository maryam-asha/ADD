<?php

namespace Database\Factories;

use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponSource;
use App\Domain\Pricing\Models\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * 10% off anything, for anyone, any number of times — each test narrows
     * the one rule it is about.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('CODE####??')),
            'name' => ['en' => 'Ten percent off', 'ar' => 'خصم عشرة بالمئة'],
            'source' => CouponSource::Admin,
            'discount_type' => CouponDiscountType::Percentage,
            'discount_value' => 10,
            'combinable_with_offers' => true,
            'new_members_only' => false,
            'is_active' => true,
        ];
    }

    public function percentage(int $percent): static
    {
        return $this->state(['discount_type' => CouponDiscountType::Percentage, 'discount_value' => $percent]);
    }

    public function amountOff(int $points): static
    {
        return $this->state(['discount_type' => CouponDiscountType::AmountOff, 'discount_value' => $points]);
    }
}
