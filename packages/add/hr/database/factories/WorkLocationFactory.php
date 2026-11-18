<?php

namespace Add\Hr\Database\Factories;

use Add\Hr\Core\Models\WorkLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkLocation>
 */
class WorkLocationFactory extends Factory
{
    protected $model = WorkLocation::class;

    public function definition(): array
    {
        $city = $this->faker->unique()->city();

        return [
            'name' => ['ar' => 'موقع '.$city, 'en' => $city],
            'is_active' => true,
        ];
    }
}
