<?php

namespace Add\Hr\Database\Factories;

use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        $job = $this->faker->unique()->jobTitle();

        return [
            'org_unit_id' => OrgUnit::factory(),
            'title' => ['ar' => 'منصب '.$job, 'en' => $job],
            'headcount' => 1,
            'is_active' => true,
        ];
    }
}
