<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\District;
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
            // Nullable until a second branch exists (PRD decision #1) — a
            // factory-made branch still gets a district by default so
            // relationship tests don't have to opt in separately.
            'district_id' => District::factory(),
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
