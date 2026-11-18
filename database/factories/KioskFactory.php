<?php

namespace Database\Factories;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Kiosk>
 */
class KioskFactory extends Factory
{
    protected $model = Kiosk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Kiosk '.fake()->unique()->numberBetween(1, 99999),
            'space_id' => Space::factory(), // SpaceFactory's default type is co_space
            'is_active' => true,
        ];
    }
}
