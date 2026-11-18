<?php

namespace Add\Hr\Database\Factories;

use Add\Hr\Core\Models\JobGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobGrade>
 */
class JobGradeFactory extends Factory
{
    protected $model = JobGrade::class;

    public function definition(): array
    {
        $rank = $this->faker->unique()->numberBetween(1, 500);

        return [
            'code' => 'G'.$rank,
            'name' => ['ar' => 'الدرجة '.$rank, 'en' => 'Grade '.$rank],
            'rank' => $rank,
        ];
    }
}
