<?php

namespace Tests\Feature\Hr;

use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Services\LeaveLedger;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Leave end to end through ADD's routes: an employee with no role files
 * it from hr/me, their manager decides it from their inbox, and each side
 * hears about it through ADD's notifications. ADD's working week ends on
 * Friday and Saturday.
 */
class HrLeaveFlowTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        // Sunday 1 March 2026.
        $this->travelTo('2026-03-01 09:00:00');
    }

    public function test_an_employee_files_leave_and_their_manager_approves_it(): void
    {
        Notification::fake();
        $managerUser = User::factory()->create();
        $manager = $this->hireFor($managerUser);
        $employeeUser = User::factory()->create();
        $employee = $this->hireFor($employeeUser, ['assignment' => [
            'org_unit_id' => $manager->currentAssignment()->org_unit_id,
            'manager_id' => $manager->id,
        ]]);
        $annual = LeaveType::query()->create(['code' => 'annual', 'name' => ['ar' => 'سنوية', 'en' => 'Annual']]);
        LeavePolicy::query()->create(['leave_type_id' => $annual->id, 'effective_from' => '2026-01-01', 'accrual_method' => 'none', 'annual_entitlement' => 14]);
        app(LeaveLedger::class)->record($employee, $annual, LedgerKind::Opening, 14, now(), 2026);

        // Sunday 8 → Saturday 14 March: Friday and Saturday are not working days.
        Sanctum::actingAs($employeeUser, ['*']);
        $approvalId = $this->postJson('/api/v1/hr/me/leave/requests', [
            'leave_type_id' => $annual->id,
            'starts_on' => '2026-03-08',
            'ends_on' => '2026-03-14',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '5')
            ->json('data.approval.id');
        Notification::assertSentTo($managerUser, GeneralNotification::class);

        Sanctum::actingAs($managerUser, ['*']);
        $this->getJson('/api/v1/hr/me/approvals')->assertOk()->assertJsonPath('data.0.id', $approvalId);
        $this->postJson("/api/v1/hr/me/approvals/{$approvalId}/approve")->assertOk();

        Notification::assertSentTo($employeeUser, GeneralNotification::class);
        Sanctum::actingAs($employeeUser, ['*']);
        $this->getJson('/api/v1/hr/me/leave/balances')->assertOk()->assertJsonPath('data.0.balance', '9');
    }

    public function test_hr_settings_and_overrides_follow_hr_permissions(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $this->getJson('/api/v1/hr/leave-types')->assertForbidden();
        $this->getJson('/api/v1/hr/approval-requests')->assertForbidden();

        Role::create(['name' => 'hr_officer', 'guard_name' => 'web'])
            ->givePermissionTo(['hr.leave.view', 'hr.approvals.manage']);
        $officer = User::factory()->create();
        $officer->assignRole('hr_officer');
        Sanctum::actingAs($officer, ['*']);
        $this->getJson('/api/v1/hr/leave-types')->assertOk();
        $this->getJson('/api/v1/hr/approval-requests')->assertOk();
    }
}
