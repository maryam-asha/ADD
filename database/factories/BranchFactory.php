<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => [
                'en' => fake()->company().' Branch',
                'ar' => 'فرع '.fake()->company(),
            ],
            'city' => [
                'en' => fake()->city(),
                'ar' => fake()->city(),
            ],
            'timezone' => 'Asia/Damascus',
            'is_active' => true,
        ];
    }
}
