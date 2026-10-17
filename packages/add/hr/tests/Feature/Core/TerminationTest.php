<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Fixtures\RecordingProvisioner;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

class TerminationTest extends TestCase
{
    use HiresEmployees;

    private RecordingProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisioner = new RecordingProvisioner;
        $this->app->instance(AccountProvisioner::class, $this->provisioner);
        $this->actingAsWith([Abilities::LIFECYCLE_MANAGE, Abilities::EMPLOYEES_VIEW, Abilities::EMPLOYEES_CREATE]);
    }

    private function terminate(int $employeeId, string $lastDay, string $reason = 'resignation')
    {
        return $this->postJson("/api/hr/employees/{$employeeId}/events", [
            'type' => 'termination',
            'effective_on' => $lastDay,
            'reason' => $reason,
            'notes' => 'Moved abroad',
        ]);
    }

    public function test_termination_in_past_deprovisions_immediately_once(): void
    {
        $employee = $this->hireEmployee(['hired_on' => now()->subYear()->toDateString()]);

        $this->terminate($employee->id, now()->subDay()->toDateString())->assertCreated();

        $fresh = $employee->fresh();
        $this->assertSame(EmployeeStatus::Terminated, $fresh->status);
        $this->assertSame('resignation', $fresh->termination_reason->value);
        $this->assertNotNull($fresh->access_revoked_at);
        $this->assertSame([$employee->id], $this->provisioner->deprovisioned);

        $this->artisan('hr:refresh-statuses')->assertSuccessful();
        $this->assertSame([$employee->id], $this->provisioner->deprovisioned);
    }

    public function test_future_termination_keeps_access_until_last_day_then_command_deprovisions_once(): void
    {
        $user = User::query()->create(['name' => 'Leaving', 'email' => 'leaving@example.test']);
        $employee = $this->hireEmployee(['user_id' => $user->id]);
        $lastDay = now()->addDays(2)->toDateString();

        $this->terminate($employee->id, $lastDay)->assertCreated();

        $this->assertSame([], $this->provisioner->deprovisioned);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
        $this->assertTrue($employee->fresh()->isEmployedOn(now()->addDays(2)));
        $this->assertFalse($employee->fresh()->isEmployedOn(now()->addDays(3)));

        $this->travel(2)->days();
        $this->artisan('hr:refresh-statuses')->assertSuccessful();
        $this->assertSame([], $this->provisioner->deprovisioned);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);

        $this->travel(1)->days();
        $this->artisan('hr:refresh-statuses')->assertSuccessful();
        $this->artisan('hr:refresh-statuses')->assertSuccessful();

        $this->assertSame([$employee->id], $this->provisioner->deprovisioned);
        $this->assertSame(EmployeeStatus::Terminated, $employee->fresh()->status);
        $this->assertNotNull($employee->fresh()->access_revoked_at);
    }

    public function test_termination_closes_assignment_and_contract_on_the_last_day(): void
    {
        $employee = $this->hireEmployee(['contract' => ['type' => 'fixed_term', 'ends_on' => now()->addYear()->toDateString()]]);
        $lastDay = now()->addWeek()->toDateString();

        $this->terminate($employee->id, $lastDay)->assertCreated();

        $this->assertSame($lastDay, $employee->assignments()->first()->effective_to->toDateString());
        $this->assertSame($lastDay, $employee->contracts()->first()->ends_on->toDateString());
    }

    public function test_termination_frees_position_seat(): void
    {
        $position = Position::factory()->create(['headcount' => 1]);
        $leaver = $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);

        $this->terminate($leaver->id, now()->subDay()->toDateString())->assertCreated();

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'hired_on' => now()->toDateString(),
            'assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id],
        ]))->assertCreated();
    }

    public function test_termination_needs_a_reason_and_cannot_repeat(): void
    {
        $employee = $this->hireEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/events", ['type' => 'termination', 'effective_on' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->terminate($employee->id, now()->toDateString())->assertCreated();
        $this->terminate($employee->id, now()->addDay()->toDateString())
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.already_terminated'));
    }

    public function test_events_cannot_be_recorded_for_a_day_outside_the_employment(): void
    {
        $employee = $this->hireEmployee(['contract' => ['probation_ends_on' => now()->addMonth()->toDateString()]]);
        $this->terminate($employee->id, now()->subDay()->toDateString())->assertCreated();

        $this->postJson("/api/hr/employees/{$employee->id}/events", ['type' => 'confirmation', 'effective_on' => now()->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.not_employed_on_date'));
    }

    public function test_rehire_after_termination_starts_new_contract_and_assignment(): void
    {
        $employee = $this->hireEmployee(['hired_on' => now()->subYears(2)->toDateString()]);
        $this->terminate($employee->id, now()->subYear()->toDateString())->assertCreated();
        $unit = OrgUnit::factory()->create();

        $this->postJson("/api/hr/employees/{$employee->id}/events", [
            'type' => 'rehire',
            'effective_on' => now()->toDateString(),
            'contract' => ['type' => 'open_ended', 'probation_ends_on' => now()->addMonths(3)->toDateString()],
            'assignment' => ['org_unit_id' => $unit->id, 'employment_type' => 'part_time', 'work_mode' => 'remote'],
        ])->assertCreated();

        $fresh = $employee->fresh();
        $this->assertSame(EmployeeStatus::Probation, $fresh->status);
        $this->assertNull($fresh->terminated_on);
        $this->assertNull($fresh->access_revoked_at);
        $this->assertSame(now()->toDateString(), $fresh->hired_on->toDateString());
        $this->assertSame($unit->id, (int) $fresh->currentAssignment()->org_unit_id);
        $this->assertSame(2, $fresh->contracts()->count());
    }

    public function test_rehire_of_a_current_employee_is_rejected(): void
    {
        $employee = $this->hireEmployee();

        $this->postJson("/api/hr/employees/{$employee->id}/events", [
            'type' => 'rehire',
            'effective_on' => now()->toDateString(),
            'contract' => ['type' => 'open_ended'],
            'assignment' => ['org_unit_id' => OrgUnit::factory()->create()->id, 'employment_type' => 'full_time', 'work_mode' => 'on_site'],
        ])->assertStatus(422)->assertJsonPath('message', __('hr::core.rehire_not_allowed'));
    }
}
