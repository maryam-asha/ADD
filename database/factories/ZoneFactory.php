<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'floor_id' => Floor::factory(),
            'label' => 'Zone '.fake()->unique()->randomLetter(),
            'sort_order' => 0,
        ];
    }
}
