<?php

namespace Add\Pm\Tests\Feature;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Tag;
use Add\Pm\Models\Task;
use Add\Pm\Models\TaskAssignee;
use Add\Pm\Tests\Fixtures\User;
use Add\Pm\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Tasks on a project: who may create, edit, move and delete them, the rules
 * a task must keep, and the board's drop ordering.
 */
class TasksTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $name): User
    {
        return User::query()->create(['name' => $name, 'email' => uniqid().'@example.test']);
    }

    private function projectWith(User ...$members): Project
    {
        $project = Project::factory()->create();
        foreach ($members as $member) {
            $project->members()->create(['user_id' => $member->id]);
        }

        return $project;
    }

    /**
     * @param  list<int>  $assignees
     */
    private function task(Project $project, array $assignees = [], array $attributes = []): Task
    {
        $task = Task::factory()->create(['project_id' => $project->id, ...$attributes]);
        foreach ($assignees as $id) {
            TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $id]);
        }

        return $task;
    }

    public function test_a_new_task_is_todo_in_memory(): void
    {
        $this->assertSame('todo', (new Task)->status->value);
    }

    public function test_a_lead_assigns_two_members_and_sees_the_shape(): void
    {
        Carbon::setTestNow('2026-03-04 09:00:00');
        $lead = $this->actingAsWith();
        $layla = $this->person('Layla');
        $omar = $this->person('Omar');
        $project = $this->projectWith($lead, $layla, $omar);
        $project->update(['lead_user_id' => $lead->id]);
        $tag = Tag::query()->create(['name' => 'Design', 'tone' => 'teal']);

        $response = $this->postJson("/api/pm/projects/{$project->id}/tasks", [
            'title' => 'Write brief',
            'description' => 'Two pages',
            'priority' => 'high',
            'starts_on' => '2026-03-05',
            'due_on' => '2026-03-10',
            'estimate_hours' => 4.5,
            'assignee_ids' => [$layla->id, $omar->id],
            'tag_ids' => [$tag->id],
        ])->assertCreated();

        $response->assertJsonPath('data.title', 'Write brief')
            ->assertJsonPath('data.status', 'todo')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.starts_on', '2026-03-05')
            ->assertJsonPath('data.due_on', '2026-03-10')
            ->assertJsonPath('data.estimate_hours', 4.5)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.created_by', $lead->id)
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.project.tone', $project->tone->value)
            ->assertJsonPath('data.tags.0.name', 'Design')
            ->assertJsonPath('data.subtask_counts', ['total' => 0, 'done' => 0])
            ->assertJsonPath('data.can', ['edit' => true, 'move' => true, 'delete' => true, 'assign' => true]);
        $this->assertEqualsCanonicalizing(['Layla', 'Omar'], array_column($response->json('data.assignees'), 'name'));
    }

    public function test_a_member_sees_can_assign_false(): void
    {
        $me = $this->actingAsWith();
        $project = $this->projectWith($me);
        $task = $this->task($project, [$me->id], ['created_by' => $me->id]);

        $this->getJson("/api/pm/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.can', ['edit' => true, 'move' => true, 'delete' => true, 'assign' => false]);
    }

    public function test_a_member_adds_a_task_for_themselves_and_is_refused_one_for_another(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($me, $other);

        $own = $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Mine'])->assertCreated();
        $this->assertSame([$me->id], array_column($own->json('data.assignees'), 'id'));

        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Theirs', 'assignee_ids' => [$other->id]])
            ->assertForbidden()->assertJsonPath('code', 'assign_forbidden');
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Both', 'assignee_ids' => [$me->id, $other->id]])
            ->assertForbidden();
        $this->assertSame(1, Task::query()->count());
    }

    public function test_a_member_moves_an_assigned_task_to_done_but_cannot_rename_it(): void
    {
        Carbon::setTestNow('2026-03-04 09:00:00');
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($me, $other);
        $task = $this->task($project, [$me->id, $other->id], ['created_by' => $other->id]);

        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'done'])->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.completed_at', '2026-03-04T09:00:00+00:00');
        $this->patchJson("/api/pm/tasks/{$task->id}", ['title' => 'Renamed'])
            ->assertForbidden()->assertJsonPath('code', 'task_forbidden');
        $this->deleteJson("/api/pm/tasks/{$task->id}")->assertForbidden();

        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'review'])->assertOk()
            ->assertJsonPath('data.completed_at', null);
    }

    public function test_a_member_cannot_move_a_task_not_assigned_to_them(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($me, $other);
        $task = $this->task($project, [$other->id]);

        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'done'])->assertForbidden();
    }

    public function test_a_member_edits_a_task_they_made_for_themselves(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($me, $other);
        $task = $this->task($project, [$me->id], ['created_by' => $me->id]);

        $this->patchJson("/api/pm/tasks/{$task->id}", ['title' => 'Better', 'assignee_ids' => [$me->id]])
            ->assertOk()->assertJsonPath('data.title', 'Better');
        $this->patchJson("/api/pm/tasks/{$task->id}", ['assignee_ids' => [$other->id]])
            ->assertForbidden()->assertJsonPath('code', 'assign_forbidden');
        $this->deleteJson("/api/pm/tasks/{$task->id}")->assertOk();
        $this->assertSame(0, Task::query()->count());
    }

    public function test_a_viewer_reads_but_every_write_is_forbidden(): void
    {
        $this->actingAsWith(['pm.projects.view']);
        $other = $this->person('Other');
        $project = $this->projectWith($other);
        $task = $this->task($project, [$other->id], ['created_by' => $other->id]);

        $this->getJson("/api/pm/projects/{$project->id}/tasks")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/pm/tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.can', ['edit' => false, 'move' => false, 'delete' => false, 'assign' => false]);
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'X'])->assertForbidden();
        $this->patchJson("/api/pm/tasks/{$task->id}", ['title' => 'X'])->assertForbidden();
        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'done'])->assertForbidden();
        $this->deleteJson("/api/pm/tasks/{$task->id}")->assertForbidden();
    }

    public function test_a_non_member_gets_404_on_every_task_route(): void
    {
        $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($other);
        $task = $this->task($project, [$other->id]);

        $this->getJson("/api/pm/projects/{$project->id}/tasks")->assertNotFound();
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'X'])->assertNotFound();
        $this->getJson("/api/pm/tasks/{$task->id}")->assertNotFound();
        $this->patchJson("/api/pm/tasks/{$task->id}", ['title' => 'X'])->assertNotFound();
        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'done'])->assertNotFound();
        $this->deleteJson("/api/pm/tasks/{$task->id}")->assertNotFound();
    }

    public function test_a_drop_orders_the_column_and_skips_tasks_the_actor_may_not_move(): void
    {
        $me = $this->actingAsWith();
        $other = $this->person('Other');
        $project = $this->projectWith($me, $other);
        $a = $this->task($project, [$me->id], ['status' => TaskStatus::Review, 'sort_order' => 0]);
        $b = $this->task($project, [$me->id], ['status' => TaskStatus::Review, 'sort_order' => 1]);
        $theirs = $this->task($project, [$other->id], ['status' => TaskStatus::Review, 'sort_order' => 2]);
        $moved = $this->task($project, [$me->id], ['status' => TaskStatus::Todo, 'sort_order' => 0]);
        $elsewhere = $this->task($project, [$me->id], ['status' => TaskStatus::Todo, 'sort_order' => 5]);

        $this->postJson("/api/pm/tasks/{$moved->id}/move", [
            'status' => 'review',
            'order' => [$b->id, $theirs->id, $moved->id, $a->id, $elsewhere->id],
        ])->assertOk();

        $this->assertSame(0, $b->fresh()->sort_order);
        $this->assertSame(1, $moved->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(2, $theirs->fresh()->sort_order);
        $this->assertSame(5, $elsewhere->fresh()->sort_order);
        $this->assertSame(TaskStatus::Todo, $elsewhere->fresh()->status);
    }

    public function test_a_move_without_an_order_puts_the_card_last(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith();
        $this->task($project, [], ['status' => TaskStatus::Review, 'sort_order' => 3]);
        $task = $this->task($project);

        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'review'])->assertOk()
            ->assertJsonPath('data.sort_order', 4);
    }

    public function test_an_assignee_who_is_not_a_member_is_refused(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $member = $this->person('Member');
        $stranger = $this->person('Stranger');
        $project = $this->projectWith($member);

        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'X', 'assignee_ids' => [$stranger->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids.0');

        $task = $this->task($project);
        $this->patchJson("/api/pm/tasks/{$task->id}", ['assignee_ids' => [$stranger->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_ids.0');
    }

    public function test_a_due_date_before_the_start_is_refused(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith();

        $this->postJson("/api/pm/projects/{$project->id}/tasks", [
            'title' => 'X', 'starts_on' => '2026-03-10', 'due_on' => '2026-03-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('due_on');

        $task = $this->task($project, [], ['starts_on' => '2026-03-10']);
        $this->patchJson("/api/pm/tasks/{$task->id}", ['due_on' => '2026-03-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_on');
    }

    public function test_the_basic_fields_are_validated(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith();

        $this->postJson("/api/pm/projects/{$project->id}/tasks", [
            'title' => '', 'priority' => 'huge', 'estimate_hours' => 1000, 'status' => 'nope',
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'priority', 'estimate_hours', 'status']);
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Fine', 'priority' => null])
            ->assertCreated()->assertJsonPath('data.priority', null);
    }

    public function test_an_archived_project_refuses_every_write_and_leaves_my_tasks(): void
    {
        $me = $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith($me);
        $task = $this->task($project, [$me->id]);
        $project->update(['is_archived' => true]);

        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'X'])
            ->assertStatus(409)->assertJsonPath('code', 'project_archived');
        $this->patchJson("/api/pm/tasks/{$task->id}", ['title' => 'X'])->assertStatus(409);
        $this->postJson("/api/pm/tasks/{$task->id}/move", ['status' => 'done'])->assertStatus(409);
        $this->deleteJson("/api/pm/tasks/{$task->id}")->assertStatus(409);
        $this->assertCount(0, $this->getJson('/api/pm/tasks?scope=assigned')->assertOk()->json('data'));
        $this->getJson("/api/pm/projects/{$project->id}/tasks")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_subtasks_stay_one_level_deep_and_are_counted_on_the_parent(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith();
        $parent = $this->task($project);

        $child = $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Child', 'parent_id' => $parent->id])
            ->assertCreated()->assertJsonPath('data.parent_id', $parent->id)->json('data.id');
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Done child', 'parent_id' => $parent->id, 'status' => 'done'])
            ->assertCreated();

        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Grandchild', 'parent_id' => $child])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $elsewhere = $this->task($this->projectWith());
        $this->postJson("/api/pm/projects/{$project->id}/tasks", ['title' => 'Lost', 'parent_id' => $elsewhere->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/pm/tasks/{$parent->id}", ['parent_id' => $child])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $list = $this->getJson("/api/pm/projects/{$project->id}/tasks")->assertOk();
        $this->assertSame([$parent->id], array_column($list->json('data'), 'id'));
        $list->assertJsonPath('data.0.subtask_counts', ['total' => 2, 'done' => 1]);

        $detail = $this->getJson("/api/pm/tasks/{$parent->id}")->assertOk();
        $this->assertCount(2, $detail->json('data.subtasks'));
        $detail->assertJsonPath('data.subtasks.0.title', 'Child');
        $detail->assertJsonPath('data.subtask_counts', ['total' => 2, 'done' => 1]);
    }

    public function test_deleting_a_task_deletes_its_subtasks(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith();
        $parent = $this->task($project);
        $child = $this->task($project, [], ['parent_id' => $parent->id]);
        $keep = $this->task($project);

        $this->deleteJson("/api/pm/tasks/{$parent->id}")->assertOk();

        $this->assertNull(Task::query()->find($parent->id));
        $this->assertNull(Task::query()->find($child->id));
        $this->assertNotNull(Task::query()->find($keep->id));
    }

    public function test_the_list_filters(): void
    {
        Carbon::setTestNow('2026-03-04 09:00:00');
        $me = $this->actingAsWith(['pm.projects.manage']);
        $layla = $this->person('Layla');
        $project = $this->projectWith($me, $layla);
        $tag = Tag::query()->create(['name' => 'Ops', 'tone' => 'slate']);
        $overdue = $this->task($project, [$layla->id], ['title' => 'Alpha report', 'due_on' => '2026-03-01', 'priority' => 'urgent']);
        $today = $this->task($project, [], ['title' => 'Beta', 'due_on' => '2026-03-04', 'status' => TaskStatus::Review]);
        $week = $this->task($project, [], ['title' => 'Gamma', 'due_on' => '2026-03-11']);
        $none = $this->task($project, [], ['title' => 'Delta']);
        $overdue->tags()->attach($tag->id);

        $ids = fn (string $query) => array_column($this->getJson("/api/pm/projects/{$project->id}/tasks?{$query}")->assertOk()->json('data'), 'id');

        $this->assertSame([$overdue->id], $ids('due=overdue'));
        $this->assertSame([$today->id], $ids('due=today'));
        $this->assertSame([$week->id], $ids('due=week'));
        $this->assertSame([$none->id], $ids('due=none'));
        $this->assertSame([$overdue->id], $ids("assignee={$layla->id}"));
        $this->assertSame([$today->id], $ids('status=review'));
        $this->assertSame([$overdue->id], $ids('priority=urgent'));
        $this->assertSame([$overdue->id], $ids("tag={$tag->id}"));
        $this->assertSame([$overdue->id], $ids('q=alpha'));
        $this->getJson("/api/pm/projects/{$project->id}/tasks?due=someday")->assertUnprocessable();
    }

    public function test_tags_are_listed_by_anyone_and_managed_by_managers(): void
    {
        $this->actingAsWith();
        Tag::query()->create(['name' => 'Ops', 'tone' => 'slate']);
        $this->getJson('/api/pm/tags')->assertOk()->assertJsonPath('data.0.name', 'Ops')->assertJsonPath('data.0.tone', 'slate');
        $this->postJson('/api/pm/tags', ['name' => 'X', 'tone' => 'teal'])->assertForbidden();

        $this->actingAsWith(['pm.projects.manage']);
        $id = $this->postJson('/api/pm/tags', ['name' => 'Design', 'tone' => 'teal'])->assertCreated()->json('data.id');
        $this->postJson('/api/pm/tags', ['name' => 'Design', 'tone' => 'teal'])->assertUnprocessable();
        $this->patchJson("/api/pm/tags/{$id}", ['name' => 'Art', 'tone' => 'cyan'])->assertOk()->assertJsonPath('data.name', 'Art');
        $this->deleteJson("/api/pm/tags/{$id}")->assertOk();
        $this->assertNull(Tag::query()->find($id));
    }
}
