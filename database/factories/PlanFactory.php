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
            'access_window_start' => null,
            'access_window_end' => null,
            'is_active' => true,
            'is_public' => true,
            'order' => 0,
        ];
    }

    /**
     * The evening package the access-window feature was built for: usable
     * only between 20:00 and midnight.
     */
    public function evening(): static
    {
        return $this->state(fn (array $attributes): array => [
            'access_window_start' => '20:00',
            'access_window_end' => '00:00',
        ]);
    }

    public function private(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => false,
        ]);
    }
}
