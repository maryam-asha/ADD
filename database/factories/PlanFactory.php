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
            'price_points' => 500,
            'duration_days' => 30,
            'included_hours' => 0,
            'overage_rate_points' => 30,
            'is_active' => true,
            'order' => 0,
        ];
    }
}
