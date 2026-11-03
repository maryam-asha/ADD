<?php

namespace Add\Hr\Tests\Feature\Approvals;

use Add\Hr\Approvals\Models\ApprovalFlow;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\Fixtures\RecordingApprovalHandler;
use Add\Hr\Tests\Fixtures\RecordingNotifier;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;

class ApprovalEngineTest extends TestCase
{
    use HiresStaff;
    use TravelsOnTheOrganisationsClock;

    private RecordingNotifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingApprovalHandler::reset();
        app(ApprovalTypes::class)->register('test', RecordingApprovalHandler::class);
        $this->notifier = new RecordingNotifier;
        $this->app->instance(HrNotifier::class, $this->notifier);
    }

    private function submit(Employee $employee): ApprovalRequest
    {
        return app(ApprovalEngine::class)->submit($employee, 'test', $employee, $employee->user_id);
    }

    public function test_default_flow_goes_to_the_direct_manager_and_their_approval_finishes_the_request(): void
    {
        $manager = $this->staff();
        $employee = $this->reportOf($manager);

        $request = $this->submit($employee);

        $this->assertSame($manager->id, $request->steps()->first()->approver_employee_id);
        $this->assertSame(['approval_pending'], $this->notifier->keysFor($manager));

        $this->actingAsEmployee($manager);
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve", ['comment' => 'ok'])
            ->assertOk()
            ->assertJsonPath('message', __('hr::approvals.approved'));

        $this->assertSame('approved', $request->refresh()->status->value);
        $this->assertSame(['before_final', 'approved'], RecordingApprovalHandler::$calls);
        $this->assertSame(['request_approved'], $this->notifier->keysFor($employee));
    }

    public function test_a_multi_step_flow_runs_its_steps_in_order(): void
    {
        ApprovalFlow::query()->create([
            'request_type' => 'test',
            'steps' => [['approver' => 'direct_manager'], ['approver' => 'ability', 'ability' => Abilities::LEAVE_MANAGE]],
        ]);
        $manager = $this->staff();
        $hr = $this->staff([Abilities::LEAVE_MANAGE]);
        $employee = $this->reportOf($manager);
        $request = $this->submit($employee);

        $this->actingAsEmployee($hr);
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsEmployee($manager);
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertOk();
        $this->assertSame('pending', $request->refresh()->status->value);
        $this->assertSame(2, $request->current_step);
        $this->assertSame([[
            Abilities::LEAVE_MANAGE,
            'approval_pending',
            ['request_id' => $request->id],
            ['target_type' => 'hr_approval_request', 'target_id' => (string) $request->id],
        ]], $this->notifier->calls);

        $this->actingAsEmployee($hr);
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.subject_id', $employee->id)
            ->assertJsonPath('data.0.steps.0.status', 'approved');
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertOk();

        $this->assertSame('approved', $request->refresh()->status->value);
        $this->assertSame(['before', 'before_final', 'approved'], RecordingApprovalHandler::$calls);
    }

    public function test_a_rejection_ends_the_request_and_cancels_later_steps(): void
    {
        ApprovalFlow::query()->create([
            'request_type' => 'test',
            'steps' => [['approver' => 'direct_manager'], ['approver' => 'ability', 'ability' => Abilities::LEAVE_MANAGE]],
        ]);
        $manager = $this->staff();
        $employee = $this->reportOf($manager);
        $request = $this->submit($employee);

        $this->actingAsEmployee($manager);
        $this->postJson("/api/hr/me/approvals/{$request->id}/reject", ['comment' => 'busy month'])->assertOk();

        $request->refresh();
        $this->assertSame('rejected', $request->status->value);
        $this->assertSame(['rejected', 'cancelled'], $request->steps->pluck('status')->map->value->all());
        $this->assertSame('busy month', $request->steps->first()->comment);
        $this->assertSame(['rejected'], RecordingApprovalHandler::$calls);
        $this->assertSame(['request_rejected'], $this->notifier->keysFor($employee));
    }

    public function test_nobody_decides_their_own_request_not_even_with_the_override(): void
    {
        $employee = $this->staff([Abilities::APPROVALS_MANAGE]);
        $request = $this->submit($employee);

        $this->actingAsEmployee($employee);
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertNotFound();
        $this->postJson("/api/hr/approval-requests/{$request->id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', __('hr::approvals.own_request'));

        $this->assertTrue($request->refresh()->isPending());
    }

    public function test_hr_can_decide_for_an_absent_approver_and_it_is_recorded_as_an_override(): void
    {
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));

        $this->actingAsWith([Abilities::APPROVALS_MANAGE]);
        $this->postJson("/api/hr/approval-requests/{$request->id}/approve")->assertOk();

        $this->assertSame('override', $request->steps()->first()->decided_via->value);
        $this->assertSame('approved', $request->refresh()->status->value);
    }

    public function test_a_step_with_no_manager_falls_back_to_the_hr_ability(): void
    {
        $employee = $this->staff();
        $request = $this->submit($employee);

        $step = $request->steps()->first();
        $this->assertNull($step->approver_employee_id);
        $this->assertSame(Abilities::APPROVALS_MANAGE, $step->approver_ability);
        $this->assertSame([[
            Abilities::APPROVALS_MANAGE,
            'approval_pending',
            ['request_id' => $request->id],
            ['target_type' => 'hr_approval_request', 'target_id' => (string) $request->id],
        ]], $this->notifier->calls);
    }

    public function test_a_unit_head_step_skips_to_the_parent_head_when_the_requester_heads_the_unit(): void
    {
        ApprovalFlow::query()->create(['request_type' => 'test', 'steps' => [['approver' => 'unit_head']]]);
        $parent = OrgUnit::factory()->create();
        $unit = OrgUnit::factory()->create(['parent_id' => $parent->id]);
        $director = $this->staff([], ['assignment' => ['org_unit_id' => $parent->id]]);
        $head = $this->staff([], ['assignment' => ['org_unit_id' => $unit->id]]);
        $parent->update(['head_employee_id' => $director->id]);
        $unit->update(['head_employee_id' => $head->id]);

        $this->assertSame($director->id, $this->submit($head)->steps()->first()->approver_employee_id);
    }

    public function test_a_delegate_decides_only_while_the_delegation_runs(): void
    {
        $manager = $this->staff();
        $deputy = $this->staff();
        $request = $this->submit($this->reportOf($manager));

        $this->actingAsEmployee($manager);
        $this->postJson('/api/hr/me/delegations', [
            'delegate_employee_id' => $deputy->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
        ])->assertCreated()->assertJsonPath('data.delegate.id', $deputy->id);

        $this->actingAsEmployee($deputy);
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(1, 'data');

        $this->travel(3)->days();
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertNotFound();

        $this->travelBack();
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertOk();
        $this->assertSame('delegate', $request->steps()->first()->decided_via->value);
    }

    public function test_delegations_are_personal(): void
    {
        $manager = $this->staff();
        $other = $this->staff();

        $this->actingAsEmployee($manager);
        $this->postJson('/api/hr/me/delegations', [
            'delegate_employee_id' => $manager->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonPath('message', __('hr::approvals.delegate_self'));
        $id = $this->postJson('/api/hr/me/delegations', [
            'delegate_employee_id' => $other->id,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->actingAsEmployee($other);
        $this->getJson('/api/hr/me/delegations')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/hr/me/delegations/{$id}")->assertNotFound();
    }

    public function test_someone_else_cannot_see_or_decide_the_request(): void
    {
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));

        $this->actingAsEmployee($this->staff());
        $this->getJson("/api/hr/me/approvals/{$request->id}")->assertNotFound();
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertNotFound();
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_handler_refusal_leaves_the_step_pending(): void
    {
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));
        RecordingApprovalHandler::$refuse = true;

        $this->actingAsEmployee($manager);
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertUnprocessable();

        $this->assertTrue($request->refresh()->isPending());
        $this->assertSame('pending', $request->steps()->first()->status->value);
    }

    public function test_a_withdrawn_request_can_no_longer_be_decided(): void
    {
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));

        app(ApprovalEngine::class)->cancel($request);

        $this->assertSame('cancelled', $request->refresh()->status->value);
        $this->actingAsEmployee($manager);
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertNotFound();
    }

    public function test_an_overdue_step_escalates_to_the_approvers_manager_then_to_hr(): void
    {
        ApprovalFlow::query()->create(['request_type' => 'test', 'steps' => [['approver' => 'direct_manager', 'timeout_hours' => 24]]]);
        $director = $this->staff();
        $manager = $this->reportOf($director);
        $request = $this->submit($this->reportOf($manager));

        $this->travel(25)->hours();
        $this->artisan('hr:approvals-timeouts')->assertSuccessful();

        $step = $request->steps()->first();
        $this->assertSame($director->id, $step->approver_employee_id);
        $this->assertNotNull($step->escalated_at);
        $this->assertSame(['approval_escalated'], $this->notifier->keysFor($director));

        $this->travel(25)->hours();
        $this->artisan('hr:approvals-timeouts')->assertSuccessful();

        $step->refresh();
        $this->assertNull($step->approver_employee_id);
        $this->assertSame(Abilities::APPROVALS_MANAGE, $step->approver_ability);
        $this->assertNull($step->due_at);
    }

    public function test_a_timeout_can_approve(): void
    {
        ApprovalFlow::query()->create(['request_type' => 'test', 'steps' => [['approver' => 'direct_manager', 'timeout_hours' => 1, 'on_timeout' => 'approve']]]);
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));

        $this->travel(30)->minutes();
        $this->artisan('hr:approvals-timeouts')->assertSuccessful();
        $this->assertTrue($request->refresh()->isPending());

        $this->travel(1)->hours();
        $this->artisan('hr:approvals-timeouts')->assertSuccessful();
        $this->assertSame('approved', $request->refresh()->status->value);
        $this->assertSame('timeout', $request->steps()->first()->decided_via->value);
    }

    public function test_a_refused_timeout_approval_goes_to_hr(): void
    {
        ApprovalFlow::query()->create(['request_type' => 'test', 'steps' => [['approver' => 'direct_manager', 'timeout_hours' => 1, 'on_timeout' => 'approve']]]);
        $manager = $this->staff();
        $request = $this->submit($this->reportOf($manager));
        RecordingApprovalHandler::$refuse = true;

        $this->travel(2)->hours();
        $this->artisan('hr:approvals-timeouts')->assertSuccessful();

        $this->assertTrue($request->refresh()->isPending());
        $this->assertSame(Abilities::APPROVALS_MANAGE, $request->steps()->first()->approver_ability);
    }

    public function test_flows_are_managed_and_validated(): void
    {
        $this->actingAsWith([Abilities::APPROVALS_MANAGE]);

        $this->getJson('/api/hr/approval-types')->assertOk()->assertJsonFragment(['test']);
        $this->postJson('/api/hr/approval-flows', ['request_type' => 'nope', 'steps' => [['approver' => 'direct_manager']]])
            ->assertUnprocessable()->assertJsonValidationErrors('request_type');
        $this->postJson('/api/hr/approval-flows', ['request_type' => 'test', 'steps' => [['approver' => 'ability']]])
            ->assertUnprocessable()->assertJsonValidationErrors('steps.0.ability');

        $id = $this->postJson('/api/hr/approval-flows', [
            'request_type' => 'test',
            'steps' => [['approver' => 'direct_manager', 'timeout_hours' => 48, 'ability' => 'ignored']],
        ])->assertCreated()
            ->assertJsonPath('data.steps.0', ['approver' => 'direct_manager', 'timeout_hours' => 48, 'on_timeout' => 'escalate'])
            ->json('data.id');

        $this->patchJson("/api/hr/approval-flows/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('message', __('hr::approvals.flow_updated'));
        $this->assertFalse(ApprovalFlow::query()->find($id)->is_active);
    }

    public function test_flow_admin_needs_the_ability(): void
    {
        $this->actingAs(User::query()->create(['name' => 'x', 'email' => 'x@example.test', 'abilities' => []]));

        $this->getJson('/api/hr/approval-flows')->assertForbidden();
        $this->getJson('/api/hr/approval-requests')->assertForbidden();
    }

    public function test_a_delegation_runs_on_the_organisations_days(): void
    {
        $this->travelToLocal('2026-03-01 12:00');
        $manager = $this->staff();
        $deputy = $this->staff();
        $request = $this->submit($this->reportOf($manager));
        $this->actingAsEmployee($manager);
        $this->postJson('/api/hr/me/delegations', ['delegate_employee_id' => $deputy->id, 'starts_on' => '2026-03-05', 'ends_on' => '2026-03-10'])->assertCreated();
        $this->postJson('/api/hr/me/delegations', ['delegate_employee_id' => $deputy->id, 'starts_on' => '2026-03-11', 'ends_on' => '2026-03-12'])->assertCreated();

        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');

        $this->getJson('/api/hr/me/delegations')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_on', '2026-03-11');
        $this->postJson('/api/hr/me/delegations', ['delegate_employee_id' => $deputy->id, 'starts_on' => '2026-03-10', 'ends_on' => '2026-03-12'])
            ->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->actingAsEmployee($deputy);
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/hr/me/approvals/{$request->id}/approve")->assertOk();
        $this->assertSame('delegate', $request->steps()->first()->decided_via->value);
    }

    public function test_an_approver_whose_last_day_has_passed_is_skipped_from_the_organisations_next_day(): void
    {
        $this->travelToLocal('2026-03-01 12:00');
        $manager = $this->staff();
        $employee = $this->reportOf($manager);
        app(EmploymentLifecycleService::class)->record($manager, EmploymentEventType::Termination, [
            'effective_on' => '2026-03-10',
            'reason' => 'resignation',
        ], null);

        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');
        $step = $this->submit($employee)->steps()->first();

        $this->assertNull($step->approver_employee_id);
        $this->assertSame(Abilities::APPROVALS_MANAGE, $step->approver_ability);
    }
}
