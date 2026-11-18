<?php

namespace Add\Pm\Database\Factories;

use Add\Pm\Enums\ProjectTone;
use Add\Pm\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst($this->faker->unique()->words(2, true)),
            'tone' => ProjectTone::Olive,
            'is_archived' => false,
        ];
    }
}
