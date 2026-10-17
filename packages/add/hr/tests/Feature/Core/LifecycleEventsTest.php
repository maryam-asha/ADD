<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;

class LifecycleEventsTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::LIFECYCLE_MANAGE, Abilities::EMPLOYEES_VIEW]);
    }

    private function record(int $employeeId, array $body)
    {
        return $this->postJson("/api/hr/employees/{$employeeId}/events", $body);
    }

    public function test_confirmation_ends_probation(): void
    {
        $employee = $this->hireEmployee(['contract' => ['probation_ends_on' => now()->addWeek()->toDateString()]]);
        $this->assertSame(EmployeeStatus::Probation, $employee->status);

        $this->record($employee->id, ['type' => 'confirmation', 'effective_on' => now()->toDateString()])
            ->assertCreated()
            ->assertJsonPath('data.type', 'confirmation');

        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
        $this->assertSame(now()->toDateString(), $employee->fresh()->confirmed_on->toDateString());
    }

    public function test_confirmation_without_probation_is_rejected(): void
    {
        $employee = $this->hireEmployee();

        $this->record($employee->id, ['type' => 'confirmation', 'effective_on' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.not_on_probation'));
    }

    public function test_promotion_closes_current_assignment_and_opens_new_one_copying_unchanged_fields(): void
    {
        $unit = OrgUnit::factory()->create();
        $junior = JobGrade::factory()->create();
        $senior = JobGrade::factory()->create();
        $employee = $this->hireEmployee([
            'hired_on' => now()->subYear()->toDateString(),
            'assignment' => ['org_unit_id' => $unit->id, 'job_grade_id' => $junior->id, 'work_mode' => 'hybrid'],
        ]);
        $on = now()->toDateString();

        $this->record($employee->id, ['type' => 'promotion', 'effective_on' => $on, 'assignment' => ['job_grade_id' => $senior->id]])
            ->assertCreated()
            ->assertJsonPath('data.payload.after.job_grade_id', $senior->id);

        $history = $this->getJson("/api/hr/employees/{$employee->id}/assignments")->assertOk()->json('data');
        $this->assertCount(2, $history);
        $this->assertSame(now()->subDay()->toDateString(), $history[0]['effective_to']);
        $this->assertSame($on, $history[1]['effective_from']);
        $this->assertNull($history[1]['effective_to']);
        $this->assertSame($senior->id, $history[1]['job_grade']['id']);
        $this->assertSame($unit->id, $history[1]['org_unit']['id']);
        $this->assertSame('hybrid', $history[1]['work_mode']);
    }

    public function test_promotion_that_changes_nothing_is_rejected(): void
    {
        $grade = JobGrade::factory()->create();
        $employee = $this->hireEmployee(['assignment' => ['job_grade_id' => $grade->id]]);

        $this->record($employee->id, ['type' => 'promotion', 'effective_on' => now()->toDateString(), 'assignment' => ['job_grade_id' => $grade->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.event_changes_nothing'));

        $this->record($employee->id, ['type' => 'transfer', 'effective_on' => now()->toDateString(), 'assignment' => ['work_mode' => 'remote']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.event_changes_nothing'));
    }

    public function test_transfer_to_another_unit_drops_the_old_units_position(): void
    {
        $position = Position::factory()->create();
        $employee = $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        $newUnit = OrgUnit::factory()->create();

        $this->record($employee->id, ['type' => 'transfer', 'effective_on' => now()->toDateString(), 'assignment' => ['org_unit_id' => $newUnit->id]])
            ->assertCreated();

        $current = $employee->fresh()->currentAssignment();
        $this->assertSame($newUnit->id, (int) $current->org_unit_id);
        $this->assertNull($current->position_id);
    }

    public function test_assignment_change_dated_on_or_before_current_start_is_rejected(): void
    {
        $employee = $this->hireEmployee(['hired_on' => now()->subMonth()->toDateString()]);
        $other = OrgUnit::factory()->create();

        $this->record($employee->id, ['type' => 'transfer', 'effective_on' => now()->subMonth()->toDateString(), 'assignment' => ['org_unit_id' => $other->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.event_date_invalid'));
    }

    public function test_manager_change_into_own_report_is_rejected(): void
    {
        $manager = $this->hireEmployee();
        $report = $this->hireEmployee(['assignment' => ['manager_id' => $manager->id]]);

        $this->record($manager->id, ['type' => 'assignment_change', 'effective_on' => now()->toDateString(), 'assignment' => ['manager_id' => $report->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.manager_cycle'));

        $this->record($manager->id, ['type' => 'assignment_change', 'effective_on' => now()->toDateString(), 'assignment' => ['manager_id' => $manager->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.manager_cycle'));
    }

    public function test_manager_cycle_is_checked_on_later_dated_changes_too(): void
    {
        $manager = $this->hireEmployee();
        $report = $this->hireEmployee();

        // From day +20 the manager will report to the report...
        $this->record($manager->id, ['type' => 'assignment_change', 'effective_on' => now()->addDays(20)->toDateString(), 'assignment' => ['manager_id' => $report->id]])
            ->assertCreated();

        // ...so the report may not report to the manager from day +5 on.
        $this->record($report->id, ['type' => 'assignment_change', 'effective_on' => now()->addDays(5)->toDateString(), 'assignment' => ['manager_id' => $manager->id]])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.manager_cycle'));
    }

    public function test_transfer_into_full_position_is_rejected(): void
    {
        $position = Position::factory()->create(['headcount' => 1]);
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);
        $other = $this->hireEmployee();

        $this->record($other->id, ['type' => 'transfer', 'effective_on' => now()->toDateString(), 'assignment' => [
            'org_unit_id' => $position->org_unit_id,
            'position_id' => $position->id,
        ]])->assertStatus(422)->assertJsonPath('message', __('hr::core.position_full'));
    }

    public function test_contract_renewal_follows_the_current_contract_and_cannot_overlap(): void
    {
        $ends = now()->addMonth();
        $employee = $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => $ends->toDateString()]]);

        $this->record($employee->id, ['type' => 'contract_renewal', 'effective_on' => $ends->toDateString(), 'contract' => ['type' => 'open_ended']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.contract_overlap'));

        $this->record($employee->id, ['type' => 'contract_renewal', 'effective_on' => $ends->copy()->addDay()->toDateString(), 'contract' => ['type' => 'open_ended']])
            ->assertCreated();

        $contracts = $this->getJson("/api/hr/employees/{$employee->id}/contracts")->assertOk()->json('data');
        $this->assertCount(2, $contracts);
        $this->assertSame('open_ended', $contracts[1]['type']);
    }

    public function test_open_ended_contract_cannot_be_renewed(): void
    {
        $employee = $this->hireEmployee();

        $this->record($employee->id, ['type' => 'contract_renewal', 'effective_on' => now()->addYear()->toDateString(), 'contract' => ['type' => 'open_ended']])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.contract_overlap'));
    }

    public function test_suspension_then_reinstatement_status_flow(): void
    {
        $employee = $this->hireEmployee();

        $this->record($employee->id, ['type' => 'suspension', 'effective_on' => now()->toDateString(), 'reason' => 'investigation'])
            ->assertCreated();
        $this->assertSame(EmployeeStatus::Suspended, $employee->fresh()->status);

        $this->record($employee->id, ['type' => 'suspension', 'effective_on' => now()->addDay()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.already_suspended'));

        $this->travel(3)->days();
        $this->record($employee->id, ['type' => 'reinstatement', 'effective_on' => now()->toDateString()])->assertCreated();
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);

        $this->record($employee->id, ['type' => 'reinstatement', 'effective_on' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.not_suspended'));
    }

    public function test_events_list_is_chronological(): void
    {
        $employee = $this->hireEmployee(['contract' => ['probation_ends_on' => now()->addWeek()->toDateString()]]);
        $this->record($employee->id, ['type' => 'confirmation', 'effective_on' => now()->toDateString()])->assertCreated();

        $this->getJson("/api/hr/employees/{$employee->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.type', 'hire')
            ->assertJsonPath('data.1.type', 'confirmation');
    }

    public function test_hire_cannot_be_recorded_as_an_event_and_type_is_required(): void
    {
        $employee = $this->hireEmployee();

        $this->record($employee->id, ['type' => 'hire', 'effective_on' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_recording_needs_the_lifecycle_ability(): void
    {
        $employee = $this->hireEmployee();
        $this->actingAsWith([Abilities::EMPLOYEES_VIEW]);

        $this->record($employee->id, ['type' => 'confirmation', 'effective_on' => now()->toDateString()])->assertForbidden();
    }
}
