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
 * Projects and who is on them: who sees which project, who may write one,
 * and what leaving a project does to a person's open work.
 */
class ProjectsTest extends TestCase
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

    public function test_a_manager_creates_a_project_with_members_and_a_lead(): void
    {
        $manager = $this->actingAsWith(['pm.projects.manage']);
        $layla = $this->person('Layla');
        $omar = $this->person('Omar');

        $response = $this->postJson('/api/pm/projects', [
            'name' => 'Launch',
            'tone' => 'teal',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-12-01',
            'members' => [$layla->id, $omar->id],
            'lead_user_id' => $layla->id,
        ])->assertCreated();

        $response->assertJsonPath('data.name', 'Launch')
            ->assertJsonPath('data.tone', 'teal')
            ->assertJsonPath('data.starts_on', '2026-11-01')
            ->assertJsonPath('data.ends_on', '2026-12-01')
            ->assertJsonPath('data.lead.name', 'Layla')
            ->assertJsonPath('data.can.manage', true)
            ->assertJsonPath('data.can.lead', true)
            ->assertJsonPath('data.counts', ['total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0]);
        $this->assertSame(['Layla', 'Omar'], array_column($response->json('data.members'), 'name'));
        $this->assertSame($manager->id, Project::query()->firstOrFail()->created_by);
    }

    public function test_a_lead_outside_the_members_is_refused(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $layla = $this->person('Layla');
        $omar = $this->person('Omar');

        $this->postJson('/api/pm/projects', [
            'name' => 'Launch', 'tone' => 'teal', 'members' => [$omar->id], 'lead_user_id' => $layla->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('lead_user_id');

        $project = $this->projectWith($omar);
        $this->patchJson("/api/pm/projects/{$project->id}", ['lead_user_id' => $layla->id])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_user_id');
    }

    public function test_the_basic_fields_are_validated(): void
    {
        $this->actingAsWith(['pm.projects.manage']);

        $this->postJson('/api/pm/projects', ['name' => '', 'tone' => '#ff0000'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'tone']);
        $this->postJson('/api/pm/projects', [
            'name' => 'X', 'tone' => 'teal', 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/pm/projects', ['name' => 'X', 'tone' => 'teal', 'members' => [9999]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.0');
    }

    public function test_validation_messages_are_the_packages_own_in_arabic(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        app()->setLocale('ar');

        $dates = $this->postJson('/api/pm/projects', [
            'name' => 'X', 'tone' => 'teal', 'starts_on' => '2026-12-01', 'ends_on' => '2026-11-01',
        ])->assertUnprocessable()->json('errors.ends_on.0');
        $person = $this->postJson('/api/pm/projects', ['name' => 'X', 'tone' => 'teal', 'members' => [9999]])
            ->assertUnprocessable()->json('errors')['members.0'][0];

        $this->assertDoesNotMatchRegularExpression('/[A-Za-z]/', $dates);
        $this->assertDoesNotMatchRegularExpression('/[A-Za-z0-9]/', $person);
    }

    public function test_a_member_lists_only_their_projects(): void
    {
        $me = $this->actingAsWith();
        $mine = $this->projectWith($me);
        $this->projectWith($this->person('Other'));

        $ids = array_column($this->getJson('/api/pm/projects')->assertOk()->json('data'), 'id');

        $this->assertSame([$mine->id], $ids);
    }

    public function test_a_viewer_reads_every_project_but_manages_none(): void
    {
        $this->actingAsWith(['pm.projects.view']);
        $project = $this->projectWith($this->person('Other'));
        Project::factory()->create();

        $this->assertCount(2, $this->getJson('/api/pm/projects')->assertOk()->json('data'));
        $this->getJson("/api/pm/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.can.manage', false)
            ->assertJsonPath('data.can.lead', false);
        $this->patchJson("/api/pm/projects/{$project->id}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_archived_projects_are_listed_only_on_request(): void
    {
        $me = $this->actingAsWith();
        $live = $this->projectWith($me);
        $old = $this->projectWith($me);
        $old->update(['is_archived' => true]);

        $this->assertSame([$live->id], array_column($this->getJson('/api/pm/projects')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(
            [$live->id, $old->id],
            array_column($this->getJson('/api/pm/projects?archived=1')->json('data'), 'id'),
        );
    }

    public function test_a_non_member_gets_404_on_show(): void
    {
        $this->actingAsWith();
        $project = $this->projectWith($this->person('Other'));

        $this->getJson("/api/pm/projects/{$project->id}")->assertNotFound();
    }

    public function test_a_member_cannot_write_projects(): void
    {
        $me = $this->actingAsWith();
        $project = $this->projectWith($me);

        $this->postJson('/api/pm/projects', ['name' => 'X', 'tone' => 'teal'])->assertForbidden();
        $this->patchJson("/api/pm/projects/{$project->id}", ['name' => 'Y'])->assertForbidden();
        $this->putJson("/api/pm/projects/{$project->id}/members", ['members' => [$me->id]])->assertForbidden();
    }

    public function test_a_member_who_leads_can_lead_but_not_manage(): void
    {
        $me = $this->actingAsWith();
        $project = $this->projectWith($me);
        $project->update(['lead_user_id' => $me->id]);

        $this->getJson("/api/pm/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.can.manage', false)
            ->assertJsonPath('data.can.lead', true);
    }

    public function test_a_manager_edits_and_archives_a_project(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $project = $this->projectWith($this->person('A'));

        $this->patchJson("/api/pm/projects/{$project->id}", ['name' => 'Renamed', 'is_archived' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('message', __('pm::pm.project_updated'));
    }

    public function test_replacing_members_unassigns_the_removed_from_open_tasks_only(): void
    {
        $this->actingAsWith(['pm.projects.manage']);
        $stays = $this->person('Stays');
        $leaves = $this->person('Leaves');
        $project = $this->projectWith($stays, $leaves);
        $project->update(['lead_user_id' => $leaves->id]);
        $open = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::InProgress]);
        $done = Task::factory()->create(['project_id' => $project->id, 'status' => TaskStatus::Done]);
        $elsewhere = Task::factory()->create(['status' => TaskStatus::Todo]);
        foreach ([$open, $done, $elsewhere] as $task) {
            TaskAssignee::query()->create(['task_id' => $task->id, 'user_id' => $leaves->id]);
        }
        TaskAssignee::query()->create(['task_id' => $open->id, 'user_id' => $stays->id]);

        $this->putJson("/api/pm/projects/{$project->id}/members", ['members' => [$stays->id]])
            ->assertOk()
            ->assertJsonPath('message', __('pm::pm.members_updated'))
            ->assertJsonPath('data.lead', null);

        $this->assertSame([$stays->id], $project->memberIds());
        $this->assertSame([$stays->id], $open->assigneeIds());
        $this->assertSame([$leaves->id], $done->assigneeIds());
        $this->assertSame([$leaves->id], $elsewhere->assigneeIds());
        $this->assertNull($project->fresh()->lead_user_id);
    }

    public function test_counts_cover_done_open_and_overdue(): void
    {
        Carbon::setTestNow('2026-11-15 10:00:00');
        $me = $this->actingAsWith();
        $project = $this->projectWith($me);
        $make = fn (TaskStatus $status, ?string $due) => Task::factory()->create([
            'project_id' => $project->id, 'status' => $status, 'due_on' => $due,
        ]);
        $make(TaskStatus::Todo, '2026-11-14');   // overdue
        $make(TaskStatus::Review, '2026-11-15'); // due today: not overdue
        $make(TaskStatus::Done, '2026-11-01');   // done: never overdue
        $make(TaskStatus::InProgress, null);     // open, no date
        $parent = $make(TaskStatus::Todo, '2026-12-01');
        Task::factory()->create([
            'project_id' => $project->id, 'parent_id' => $parent->id, 'status' => TaskStatus::Todo, 'due_on' => '2026-11-10',
        ]); // a subtask counts too

        $this->getJson("/api/pm/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.counts', ['total' => 6, 'done' => 1, 'open' => 5, 'overdue' => 2]);
    }
}
