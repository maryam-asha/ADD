<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\ChecklistTask;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;

class ChecklistTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::SETTINGS_MANAGE, Abilities::CHECKLISTS_MANAGE, Abilities::LIFECYCLE_MANAGE]);
    }

    private function template(string $kind, array $items, bool $active = true): void
    {
        $this->postJson('/api/hr/checklist-templates', [
            'kind' => $kind,
            'name' => ['ar' => 'قائمة '.$kind],
            'items' => $items,
            'is_active' => $active,
        ])->assertCreated();
    }

    public function test_hire_creates_onboarding_tasks_with_due_dates(): void
    {
        $this->template('onboarding', [
            ['title' => ['ar' => 'إنشاء الحساب', 'en' => 'Create account'], 'due_offset_days' => 0],
            ['title' => ['ar' => 'رفع الوثائق'], 'due_offset_days' => 7],
        ]);
        $hiredOn = now()->toDateString();

        $employee = $this->hireEmployee(['hired_on' => $hiredOn]);

        $this->getJson("/api/hr/employees/{$employee->id}/checklist")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.kind', 'onboarding')
            ->assertJsonPath('data.0.title.en', 'Create account')
            ->assertJsonPath('data.0.due_on', $hiredOn)
            ->assertJsonPath('data.1.due_on', now()->addDays(7)->toDateString())
            ->assertJsonPath('data.1.completed_at', null);
    }

    public function test_termination_creates_offboarding_tasks(): void
    {
        $this->template('offboarding', [['title' => ['ar' => 'استرداد البطاقة'], 'due_offset_days' => 0]]);
        $employee = $this->hireEmployee();
        $lastDay = now()->addWeek()->toDateString();

        $this->postJson("/api/hr/employees/{$employee->id}/events", ['type' => 'termination', 'effective_on' => $lastDay, 'reason' => 'resignation'])
            ->assertCreated();

        $task = ChecklistTask::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame('offboarding', $task->kind->value);
        $this->assertSame($lastDay, $task->due_on->toDateString());
    }

    public function test_inactive_templates_are_ignored(): void
    {
        $this->template('onboarding', [['title' => ['ar' => 'مهمة'], 'due_offset_days' => 0]], active: false);

        $employee = $this->hireEmployee();

        $this->assertSame(0, ChecklistTask::query()->where('employee_id', $employee->id)->count());
    }

    public function test_completing_and_reopening_a_task(): void
    {
        $this->template('onboarding', [['title' => ['ar' => 'مهمة'], 'due_offset_days' => 1]]);
        $employee = $this->hireEmployee();
        $task = ChecklistTask::query()->where('employee_id', $employee->id)->sole();

        $this->patchJson("/api/hr/checklist-tasks/{$task->id}", ['completed' => true, 'notes' => 'done at reception'])
            ->assertOk()
            ->assertExactJson(['message' => __('hr::core.checklist_task_updated')]);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame('done at reception', $task->fresh()->notes);

        $this->patchJson("/api/hr/checklist-tasks/{$task->id}", ['completed' => false])->assertOk();
        $this->assertNull($task->fresh()->completed_at);
        $this->assertNull($task->fresh()->completed_by);
    }

    public function test_template_items_validate_translatable_titles(): void
    {
        $this->postJson('/api/hr/checklist-templates', [
            'kind' => 'onboarding',
            'name' => ['ar' => 'قائمة'],
            'items' => [['title' => ['en' => 'No Arabic'], 'due_offset_days' => 0]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.title.ar');

        $this->postJson('/api/hr/checklist-templates', [
            'kind' => 'onboarding',
            'name' => ['ar' => 'قائمة'],
            'items' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_checklist_needs_its_ability(): void
    {
        $employee = $this->hireEmployee();
        $this->actingAsWith([Abilities::EMPLOYEES_VIEW]);

        $this->getJson("/api/hr/employees/{$employee->id}/checklist")->assertForbidden();
        $this->getJson('/api/hr/checklist-templates')->assertForbidden();
    }
}
