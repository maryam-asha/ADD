<?php

namespace Database\Factories;

use App\Domain\Finance\Models\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Currency>
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->currencyCode(),
            'name' => [
                'en' => fake()->words(2, true),
                'ar' => fake()->word(),
            ],
            'symbol' => fake()->randomElement(['$', '€', '£', '¤']),
            'decimal_places' => 2,
            'is_base' => false,
            'is_active' => true,
            'order' => null,
        ];
    }

    public function base(): static
    {
        return $this->state(fn () => ['is_base' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
