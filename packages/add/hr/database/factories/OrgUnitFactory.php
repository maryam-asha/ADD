<?php

namespace Add\Hr\Database\Factories;

use Add\Hr\Core\Models\OrgUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrgUnit>
 */
class OrgUnitFactory extends Factory
{
    protected $model = OrgUnit::class;

    public function definition(): array
    {
        $word = $this->faker->unique()->word();

        return [
            'name' => ['ar' => 'وحدة '.$word, 'en' => ucfirst($word).' unit'],
            'is_active' => true,
        ];
    }
}
