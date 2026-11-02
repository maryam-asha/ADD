<?php

namespace Database\Factories;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanSpaceHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanSpaceHour>
 */
class PlanSpaceHourFactory extends Factory
{
    protected $model = PlanSpaceHour::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'space_id' => null,
            'space_type' => SpaceType::Room,
            'hours' => 5,
            'order' => 0,
        ];
    }
}
