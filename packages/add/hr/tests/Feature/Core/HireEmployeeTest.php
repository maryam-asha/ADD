<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Enums\EmployeeStatus;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

class HireEmployeeTest extends TestCase
{
    use HiresEmployees;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsWith([Abilities::EMPLOYEES_CREATE, Abilities::EMPLOYEES_VIEW]);
    }

    private function provisionerReturning(int|string|null $userId): void
    {
        $this->app->instance(AccountProvisioner::class, new class($userId) implements AccountProvisioner
        {
            public function __construct(private readonly int|string|null $userId) {}

            public function provision(Employee $employee): int|string|null
            {
                return $this->userId;
            }

            public function deprovision(Employee $employee): void {}
        });
    }

    public function test_hire_creates_employee_contract_assignment_and_hire_event(): void
    {
        $unit = OrgUnit::factory()->create();
        $position = Position::factory()->create(['org_unit_id' => $unit->id]);

        $hiredOn = now()->subMonths(2)->toDateString();
        $endsOn = now()->addYear()->toDateString();

        $response = $this->postJson('/api/hr/employees', $this->hirePayload([
            'name' => ['ar' => 'نور دياب', 'en' => 'Nour Diab'],
            'phone' => '+963944210118',
            'hired_on' => $hiredOn,
            'contract' => ['type' => 'fixed_term', 'ends_on' => $endsOn, 'probation_ends_on' => now()->addMonth()->toDateString(), 'notice_period_days' => 30],
            'assignment' => ['org_unit_id' => $unit->id, 'position_id' => $position->id, 'work_mode' => 'hybrid'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name.en', 'Nour Diab')
            ->assertJsonPath('data.hired_on', $hiredOn)
            ->assertJsonPath('data.status', 'probation')
            ->assertJsonPath('data.current_contract.type', 'fixed_term')
            ->assertJsonPath('data.current_contract.ends_on', $endsOn)
            ->assertJsonPath('data.current_assignment.org_unit.id', $unit->id)
            ->assertJsonPath('data.current_assignment.position.id', $position->id)
            ->assertJsonPath('data.current_assignment.work_mode', 'hybrid');

        $employee = Employee::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, $employee->contracts()->count());
        $this->assertSame(1, $employee->assignments()->count());
        $this->assertSame(['hire'], $employee->events()->pluck('type')->map->value->all());
        $this->assertDatabaseHas('hr_audit_entries', ['action' => 'employee_hired', 'subject_id' => $employee->id]);
    }

    public function test_employee_number_is_generated_from_prefix_and_id(): void
    {
        $employee = $this->hireEmployee();

        $this->assertSame('EMP-'.str_pad((string) $employee->id, 4, '0', STR_PAD_LEFT), $employee->employee_number);
    }

    public function test_a_given_employee_number_is_kept(): void
    {
        $this->assertSame('X-77', $this->hireEmployee(['employee_number' => 'X-77'])->employee_number);
    }

    public function test_hire_with_require_user_and_no_account_is_rejected_and_nothing_is_saved(): void
    {
        config(['hr.require_user' => true]);

        $this->postJson('/api/hr/employees', $this->hirePayload())
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.user_required'));

        $this->assertDatabaseCount('hr_employees', 0);
        $this->assertDatabaseCount('hr_contracts', 0);
        $this->assertDatabaseCount('hr_employment_events', 0);
    }

    public function test_provisioner_result_is_linked_when_no_user_id_given(): void
    {
        $user = User::query()->create(['name' => 'Linked', 'email' => 'linked@example.test']);
        $this->provisionerReturning($user->id);
        config(['hr.require_user' => true]);

        $this->assertSame($user->id, $this->hireEmployee()->user_id);
    }

    public function test_an_explicit_user_id_is_linked_without_asking_the_provisioner(): void
    {
        $user = User::query()->create(['name' => 'Explicit', 'email' => 'explicit@example.test']);
        $this->provisionerReturning(999);

        $this->assertSame($user->id, $this->hireEmployee(['user_id' => $user->id])->user_id);
    }

    public function test_user_already_linked_to_another_employee_is_rejected(): void
    {
        $user = User::query()->create(['name' => 'Taken', 'email' => 'taken@example.test']);
        $this->hireEmployee(['user_id' => $user->id]);

        $this->postJson('/api/hr/employees', $this->hirePayload(['user_id' => $user->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('user_id');

        $this->provisionerReturning($user->id);
        $this->postJson('/api/hr/employees', $this->hirePayload())
            ->assertStatus(422)
            ->assertJsonPath('message', __('hr::core.user_already_employee'));
    }

    public function test_full_position_rejects_hire(): void
    {
        $position = Position::factory()->create(['headcount' => 1]);
        $this->hireEmployee(['assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id]]);

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'assignment' => ['org_unit_id' => $position->org_unit_id, 'position_id' => $position->id],
        ]))->assertStatus(422)->assertJsonPath('message', __('hr::core.position_full'));
    }

    public function test_position_from_another_unit_is_rejected(): void
    {
        $position = Position::factory()->create();
        $otherUnit = OrgUnit::factory()->create();

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'assignment' => ['org_unit_id' => $otherUnit->id, 'position_id' => $position->id],
        ]))->assertStatus(422)->assertJsonPath('message', __('hr::core.position_unit_mismatch'));
    }

    public function test_manager_must_be_employed(): void
    {
        $manager = $this->hireEmployee(['hired_on' => now()->addMonth()->toDateString()]);

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'hired_on' => now()->toDateString(),
            'assignment' => ['manager_id' => $manager->id],
        ]))->assertStatus(422)->assertJsonPath('message', __('hr::core.manager_not_employed'));
    }

    public function test_fixed_term_needs_an_end_date_after_hire(): void
    {
        $this->postJson('/api/hr/employees', $this->hirePayload([
            'hired_on' => '2026-03-01',
            'contract' => ['type' => 'fixed_term'],
        ]))->assertStatus(422)->assertJsonValidationErrors('contract.ends_on');

        $this->postJson('/api/hr/employees', $this->hirePayload([
            'hired_on' => '2026-03-01',
            'contract' => ['type' => 'fixed_term', 'ends_on' => '2026-02-01'],
        ]))->assertStatus(422)->assertJsonValidationErrors('contract.ends_on');
    }

    public function test_future_hire_is_upcoming_and_not_yet_employed(): void
    {
        $employee = $this->hireEmployee(['hired_on' => now()->addWeek()->toDateString()]);

        $this->assertSame(EmployeeStatus::Upcoming, $employee->status);
        $this->assertFalse($employee->isEmployedOn(now()));
        $this->assertTrue($employee->isEmployedOn(now()->addWeek()));
    }

    public function test_hiring_requires_the_create_ability(): void
    {
        $this->actingAsWith([Abilities::EMPLOYEES_VIEW]);

        $this->postJson('/api/hr/employees', $this->hirePayload())->assertForbidden();
    }
}
