<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeatDesk>
 */
class SeatDeskFactory extends Factory
{
    protected $model = SeatDesk::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'space_id' => Space::factory(),
            'qr_point_id' => null,
            'label' => 'D-'.fake()->unique()->numberBetween(1, 999),
        ];
    }
}
