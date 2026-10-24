<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Catalog\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name' => ['ar' => $name, 'en' => $name],
        ];
    }

    public function in(Location $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }
}
