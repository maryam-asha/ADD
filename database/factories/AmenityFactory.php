<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Amenity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Amenity>
 */
class AmenityFactory extends Factory
{
    protected $model = Amenity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return [
            'key' => 'amenity_'.$word,
            'name' => ['en' => ucfirst($word), 'ar' => $word],
            'icon' => 'check-circle',
            'order' => 0,
            'is_active' => true,
        ];
    }
}
