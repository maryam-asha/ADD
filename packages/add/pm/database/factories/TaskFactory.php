<?php

namespace Add\Pm\Database\Factories;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => ucfirst($this->faker->unique()->words(3, true)),
            'status' => TaskStatus::Todo,
            'sort_order' => 0,
        ];
    }
}
