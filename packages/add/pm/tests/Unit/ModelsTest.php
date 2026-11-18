<?php

namespace Add\Pm\Tests\Unit;

use Add\Pm\Enums\ProjectTone;
use Add\Pm\Enums\TaskPriority;
use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Tag;
use Add\Pm\Models\Task;
use Add\Pm\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The pm tables and models round-trip a project with its members and a task
 * with a subtask, assignees and tags, and every model keeps the default
 * guard behind a declared $fillable.
 */
class ModelsTest extends TestCase
{
    public function test_a_project_and_its_tasks_round_trip(): void
    {
        $project = Project::factory()->create([
            'name' => 'Launch',
            'tone' => ProjectTone::Teal,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-12-31',
        ]);
        $project->members()->createMany([['user_id' => 9], ['user_id' => 3]]);

        $task = Task::factory()->create(['project_id' => $project->id, 'priority' => TaskPriority::High, 'due_on' => '2026-11-20']);
        $sub = Task::factory()->create(['project_id' => $project->id, 'parent_id' => $task->id]);
        $task->assignees()->createMany([['user_id' => 7], ['user_id' => 2]]);
        $tag = Tag::query()->create(['name' => 'urgent-fix', 'tone' => ProjectTone::Olive]);
        $task->tags()->attach($tag->id);

        $project = $project->fresh();
        $task = $task->fresh();

        $this->assertSame([3, 9], $project->memberIds());
        $this->assertSame(2, $project->tasks()->count());
        $this->assertSame('2026-11-01', $project->starts_on->toDateString());
        $this->assertSame(ProjectTone::Teal, $project->tone);
        $this->assertSame(1, $task->subtasks()->count());
        $this->assertTrue($sub->fresh()->parent->is($task));
        $this->assertTrue($task->project->is($project));
        $this->assertSame([2, 7], $task->assigneeIds());
        $this->assertSame(['urgent-fix'], $task->tags->pluck('name')->all());
        $this->assertSame(TaskStatus::Todo, $task->status);
        $this->assertSame(TaskPriority::High, $task->priority);
        $this->assertSame('2026-11-20', $task->due_on->toDateString());
    }

    public function test_priority_ranks_run_from_urgent_down_to_low(): void
    {
        $this->assertSame([40, 30, 20, 10], array_map(
            fn (TaskPriority $p) => $p->rank(),
            [TaskPriority::Urgent, TaskPriority::High, TaskPriority::Normal, TaskPriority::Low],
        ));
    }

    public function test_deleting_a_task_removes_its_subtasks_and_pivots(): void
    {
        $task = Task::factory()->create();
        Task::factory()->create(['project_id' => $task->project_id, 'parent_id' => $task->id]);
        $task->assignees()->create(['user_id' => 1]);

        $task->delete();

        $this->assertSame(0, Task::query()->count());
        $this->assertSame(0, $this->app['db']->table('pm_task_assignees')->count());
    }

    public function test_every_model_declares_fillable_and_guards_the_rest(): void
    {
        $offenders = [];
        $src = realpath(__DIR__.'/../../src/Models');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'Add\\Pm\\Models\\'.$file->getBasename('.php');
            $this->assertTrue(is_subclass_of($class, Model::class));
            $model = new $class;
            if ($model->getFillable() === [] || $model->getGuarded() !== ['*']) {
                $offenders[] = $class;
            }
        }

        $this->assertSame([], $offenders);
    }
}
