<?php

namespace Add\Pm\Tests\Feature;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Task;
use Add\Pm\Models\TaskAssignee;
use Add\Pm\Tests\Fixtures\User;
use Add\Pm\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * "My tasks": what a person has been given, made or can see across their
 * live projects, and the counts shown above it, against a fixed today.
 */
class MyTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-04 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => uniqid().'@example.test']);
    }

    /**
     * @param  list<int>  $assignees
     */
    private function task(Project $project, array $assignees, array $attributes = []): Task
    {
        $task = Task::factory()->create(['project_id' => $project->id, ...$attributes]);
        foreach ($assignees as $id) {
            TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $id]);
        }

        return $task;
    }

    /**
     * @return list<int>
     */
    private function ids(string $query): array
    {
        return array_column($this->getJson("/api/pm/tasks?{$query}")->assertOk()->json('data'), 'id');
    }

    public function test_the_scopes_pick_assigned_created_and_all(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = Project::factory()->create();
        $project->members()->create(['user_id' => $me->id]);
        $project->members()->create(['user_id' => $other->id]);
        $foreign = Project::factory()->create();
        $foreign->members()->create(['user_id' => $other->id]);

        $assigned = $this->task($project, [$me->id], ['created_by' => $other->id]);
        $subtask = $this->task($project, [$me->id], ['parent_id' => $assigned->id, 'created_by' => $other->id]);
        $created = $this->task($project, [$other->id], ['created_by' => $me->id]);
        $neither = $this->task($project, [$other->id], ['created_by' => $other->id]);
        $this->task($foreign, [$me->id]); // a project they are not in

        $this->assertEqualsCanonicalizing([$assigned->id, $subtask->id], $this->ids(''));
        $this->assertEqualsCanonicalizing([$assigned->id, $subtask->id], $this->ids('scope=assigned'));
        $this->assertSame([$created->id], $this->ids('scope=created'));
        $this->assertEqualsCanonicalizing(
            [$assigned->id, $subtask->id, $created->id, $neither->id],
            $this->ids('scope=all'),
        );
        $row = collect($this->getJson('/api/pm/tasks')->json('data'))->firstWhere('id', $assigned->id);
        $this->assertSame(['total' => 1, 'done' => 0], $row['subtask_counts']);
        $this->assertArrayNotHasKey('subtasks', $row);
        $this->getJson('/api/pm/tasks?scope=bogus')->assertUnprocessable();
    }

    public function test_the_list_is_ordered_by_due_date_then_priority(): void
    {
        $me = $this->actingAsWith();
        $project = Project::factory()->create();
        $project->members()->create(['user_id' => $me->id]);
        $undated = $this->task($project, [$me->id]);
        $late = $this->task($project, [$me->id], ['due_on' => '2026-03-09']);
        $lowSoon = $this->task($project, [$me->id], ['due_on' => '2026-03-05', 'priority' => 'low']);
        $urgentSoon = $this->task($project, [$me->id], ['due_on' => '2026-03-05', 'priority' => 'urgent']);

        $this->assertSame([$urgentSoon->id, $lowSoon->id, $late->id, $undated->id], $this->ids(''));
    }

    public function test_the_counts_are_measured_against_today(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = Project::factory()->create();
        $project->members()->create(['user_id' => $me->id]);
        $archived = Project::factory()->create(['is_archived' => true]);
        $archived->members()->create(['user_id' => $me->id]);

        $this->task($project, [$me->id], ['due_on' => '2026-03-03']);                                        // overdue
        $this->task($project, [$me->id], ['due_on' => '2026-02-01', 'status' => TaskStatus::InProgress]);    // overdue
        $this->task($project, [$me->id], ['due_on' => '2026-02-01', 'status' => TaskStatus::Done]);          // completed, not overdue
        $this->task($project, [$me->id], ['due_on' => '2026-03-04']);                                        // today
        $this->task($project, [$me->id], ['due_on' => '2026-03-05']);                                        // week
        $this->task($project, [$me->id], ['due_on' => '2026-03-11']);                                        // week (today + 7)
        $this->task($project, [$me->id], ['due_on' => '2026-03-12']);                                        // later
        $this->task($project, [$me->id], ['due_on' => null, 'status' => TaskStatus::Done]);                  // completed
        $this->task($project, [$me->id], ['due_on' => '2026-03-04', 'status' => TaskStatus::Done]);          // completed, not today
        $this->task($project, [$me->id], ['due_on' => '2026-03-07', 'status' => TaskStatus::Done]);          // completed, not week
        $this->task($project, [$other->id], ['due_on' => '2026-03-03']);                                     // someone else's
        $this->task($archived, [$me->id], ['due_on' => '2026-03-03']);                                       // archived project

        $this->getJson('/api/pm/tasks/counts')->assertOk()
            ->assertExactJson(['data' => ['overdue' => 2, 'today' => 1, 'week' => 2, 'completed' => 4]]);
    }

    public function test_the_due_filter_applies_to_my_tasks_too(): void
    {
        $me = $this->actingAsWith();
        $project = Project::factory()->create();
        $project->members()->create(['user_id' => $me->id]);
        $today = $this->task($project, [$me->id], ['due_on' => '2026-03-04']);
        $this->task($project, [$me->id], ['due_on' => '2026-03-05']);

        $this->assertSame([$today->id], $this->ids('due=today'));
    }
}
