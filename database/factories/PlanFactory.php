<?php

namespace Database\Factories;

use App\Domain\Membership\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => [
                'en' => fake()->words(2, true).' Plan',
                'ar' => 'خطة '.fake()->word(),
            ],
            'is_subscription' => true,
            'price' => fake()->randomFloat(2, 20, 200),
            'pricing_currency' => 'USD',
            'duration_days' => 30,
            'included_hours' => 0,
            'overage_rate' => null,
            'is_active' => true,
            'order' => 0,
        ];
    }
}
