<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => [
                'en' => fake()->unique()->city().' District',
                'ar' => 'منطقة '.fake()->unique()->city(),
            ],
        ];
    }
}
