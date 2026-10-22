<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Core\Models\ChecklistTask;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;

/**
 * The checklist tasks HR gave the employee, read and ticked in
 * self-service — never HR's own tasks, never someone else's, and never
 * HR's notes on them.
 */
class MyTasksTest extends TestCase
{
    use HiresStaff;

    private Employee $me;

    private Employee $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-22 09:00:00');
        $this->me = $this->staff();
        $this->colleague = $this->staff();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function task(Employee $employee, array $attributes = []): ChecklistTask
    {
        return $employee->checklistTasks()->create([
            'kind' => 'onboarding',
            'assignee' => 'employee',
            'title' => ['ar' => 'مهمة '.uniqid()],
            ...$attributes,
        ]);
    }

    public function test_an_employee_reads_only_their_own_tasks_for_them(): void
    {
        $done = $this->task($this->me, ['due_on' => '2026-10-20', 'completed_at' => now()->subDay(), 'completed_by' => 1, 'sort' => 0]);
        $undated = $this->task($this->me, ['sort' => 1]);
        $soon = $this->task($this->me, ['due_on' => '2026-10-25', 'sort' => 2, 'notes' => 'HR only']);
        $this->task($this->me, ['assignee' => 'hr', 'due_on' => '2026-10-23']);
        $this->task($this->colleague, ['due_on' => '2026-10-23']);
        $this->actingAsEmployee($this->me);

        $response = $this->getJson('/api/hr/me/tasks')->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame([$soon->id, $undated->id, $done->id], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.0.title.ar', $soon->title['ar'])
            ->assertJsonPath('data.0.kind', 'onboarding')
            ->assertJsonPath('data.0.due_on', '2026-10-25')
            ->assertJsonPath('data.0.completed_at', null)
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.completed_by');
    }

    public function test_an_employee_completes_and_reopens_their_task(): void
    {
        $task = $this->task($this->me);
        $user = $this->actingAsEmployee($this->me);

        $this->postJson("/api/hr/me/tasks/{$task->id}/complete")->assertOk()
            ->assertExactJson(['message' => __('hr::core.my_task_completed')]);
        $first = $task->fresh()->completed_at;
        $this->assertNotNull($first);
        $this->assertSame($user->id, (int) $task->fresh()->completed_by);

        // A second tick (another tab, a double click) keeps the first.
        $this->travel(1)->hours();
        $this->postJson("/api/hr/me/tasks/{$task->id}/complete")->assertOk();
        $this->assertTrue($first->equalTo($task->fresh()->completed_at));

        $this->postJson("/api/hr/me/tasks/{$task->id}/reopen")->assertOk()
            ->assertExactJson(['message' => __('hr::core.my_task_reopened')]);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertNull($task->fresh()->completed_by);
    }

    public function test_a_task_not_given_to_the_employee_is_refused(): void
    {
        $hrs = $this->task($this->me, ['assignee' => 'hr']);
        $theirs = $this->task($this->colleague);
        $this->actingAsEmployee($this->me);

        foreach ([$hrs, $theirs] as $task) {
            foreach (['complete', 'reopen'] as $action) {
                $this->postJson("/api/hr/me/tasks/{$task->id}/{$action}")->assertForbidden()
                    ->assertJsonPath('message', __('hr::core.not_your_task'));
            }
            $this->assertNull($task->fresh()->completed_at);
        }
    }
}
