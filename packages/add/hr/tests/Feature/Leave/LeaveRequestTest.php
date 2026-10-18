<?php

namespace Add\Hr\Tests\Feature\Leave;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Leave\Models\CoverageRule;
use Add\Hr\Leave\Models\Holiday;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Tests\Concerns\BuildsLeave;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Weekends are Saturday and Sunday (the package default). Monday 2 March
 * 2026 is "today".
 */
class LeaveRequestTest extends TestCase
{
    use BuildsLeave;
    use HiresStaff;

    private Employee $manager;

    private Employee $employee;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-02 09:00:00');

        $this->manager = $this->staff();
        $this->employee = $this->reportOf($this->manager);
        $this->annual = $this->leaveType(['allows_half_day' => true]);
        $this->policy($this->annual);
        $this->giveBalance($this->employee, $this->annual, 10);
    }

    private function submit(array $data, ?Employee $as = null)
    {
        $this->actingAsEmployee($as ?? $this->employee);

        return $this->postJson('/api/hr/me/leave/requests', ['leave_type_id' => $this->annual->id, ...$data]);
    }

    private function approveAsManager(int $leaveRequestId): void
    {
        $approval = LeaveRequest::query()->findOrFail($leaveRequestId)->approval_request_id;
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")->assertOk();
    }

    public function test_a_request_counts_working_days_and_waits_for_the_manager(): void
    {
        Holiday::query()->create(['name' => ['ar' => 'عيد'], 'starts_on' => '2020-03-11', 'ends_on' => '2020-03-11', 'recurs_annually' => true]);

        // Mon 9 → Fri 13 March, minus the recurring holiday on Wednesday.
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-13', 'reason' => 'family'])
            ->assertCreated()
            ->assertJsonPath('data.amount', '4')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.approval.current_step', 1);

        $this->actingAsEmployee($this->manager);
        $this->getJson('/api/hr/me/approvals')->assertOk()
            ->assertJsonPath('data.0.subject.amount', '4')
            ->assertJsonPath('data.0.subject.reason', 'family');
    }

    public function test_approval_books_the_days_and_the_balances_show_them(): void
    {
        $id = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->json('data.id');

        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/leave/balances')->assertOk()
            ->assertJsonPath('data.0.balance', '10')
            ->assertJsonPath('data.0.pending', '2')
            ->assertJsonPath('data.0.available', '8');

        $this->approveAsManager($id);

        $this->assertSame('approved', LeaveRequest::query()->find($id)->status->value);
        $this->assertSame(8.0, $this->balanceOf($this->employee, $this->annual));
        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/leave/ledger')->assertOk()
            ->assertJsonPath('data.1.kind', 'usage')
            ->assertJsonPath('data.1.amount', '-2');
    }

    public function test_a_request_beyond_the_balance_is_refused_unless_the_policy_allows_going_negative(): void
    {
        // Eleven working days against a balance of ten.
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-23'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('hr::leave.insufficient_balance', ['available' => '10']));

        $this->annual->policies()->update(['max_negative' => 1]);

        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-23'])->assertCreated();
    }

    public function test_pending_requests_hold_their_days(): void
    {
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-13'])->assertCreated();
        $this->submit(['starts_on' => '2026-03-16', 'ends_on' => '2026-03-20'])->assertCreated();

        $this->submit(['starts_on' => '2026-03-23', 'ends_on' => '2026-03-23'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('hr::leave.insufficient_balance', ['available' => '0']));
    }

    public function test_overlapping_requests_are_refused(): void
    {
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->assertCreated();

        $this->submit(['starts_on' => '2026-03-10', 'ends_on' => '2026-03-11'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('hr::leave.overlap'));
    }

    public function test_half_days_and_hours(): void
    {
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-09', 'half_day' => 'first_half'])
            ->assertCreated()->assertJsonPath('data.amount', '0.5');
        $this->submit(['starts_on' => '2026-03-10', 'ends_on' => '2026-03-11', 'half_day' => 'first_half'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.half_day_not_allowed'));

        $permission = $this->leaveType(['unit' => 'hour', 'tracks_balance' => false]);
        $this->submit(['leave_type_id' => $permission->id, 'starts_on' => '2026-03-12', 'ends_on' => '2026-03-12', 'hours' => 2.5])
            ->assertCreated()->assertJsonPath('data.amount', '2.5')->assertJsonPath('data.hours', '2.5');
        $this->submit(['leave_type_id' => $permission->id, 'starts_on' => '2026-03-13', 'ends_on' => '2026-03-13', 'hours' => 2, 'half_day' => 'first_half'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.hours_on_one_day'));
        $this->submit(['leave_type_id' => $permission->id, 'starts_on' => '2026-03-14', 'ends_on' => '2026-03-14', 'hours' => 2])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.no_working_days'));
    }

    public function test_a_weekend_only_request_has_no_working_days(): void
    {
        $this->submit(['starts_on' => '2026-03-07', 'ends_on' => '2026-03-08'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.no_working_days'));
    }

    public function test_a_type_without_a_policy_for_the_employee_is_refused_but_untracked_types_need_none(): void
    {
        $sick = $this->leaveType();
        $this->submit(['leave_type_id' => $sick->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.not_eligible'));

        $unpaid = $this->leaveType(['tracks_balance' => false, 'is_paid' => false]);
        $this->submit(['leave_type_id' => $unpaid->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-27'])
            ->assertCreated()->assertJsonPath('data.amount', '15');
    }

    public function test_an_attachment_is_required_and_stored_encrypted_when_the_type_asks_for_one(): void
    {
        Storage::fake('local');
        $medical = DocumentType::factory()->create(['has_expiry' => false]);
        $sick = $this->leaveType(['tracks_balance' => false, 'attachment_document_type_id' => $medical->id]);

        $this->submit(['leave_type_id' => $sick->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.attachment_required'));

        $this->actingAsEmployee($this->employee);
        $response = $this->post('/api/hr/me/leave/requests', [
            'leave_type_id' => $sick->id,
            'starts_on' => '2026-03-09',
            'ends_on' => '2026-03-09',
            'attachment' => UploadedFile::fake()->createWithContent('note.pdf', '%PDF medical note'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = LeaveRequest::query()->findOrFail($response->json('data.id'))->document;
        $this->assertSame($medical->id, $document->document_type_id);
        $this->assertStringNotContainsString('medical note', Storage::disk('local')->get($document->path));
    }

    public function test_cancelling_a_pending_request_withdraws_its_approval(): void
    {
        $id = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->json('data.id');

        $this->postJson("/api/hr/me/leave/requests/{$id}/cancel")->assertOk();

        $request = LeaveRequest::query()->find($id);
        $this->assertSame('cancelled', $request->status->value);
        $this->assertSame('cancelled', $request->approval->status->value);
    }

    public function test_cancelling_approved_leave_before_it_starts_gives_the_days_back(): void
    {
        $id = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->json('data.id');
        $this->approveAsManager($id);

        $this->actingAsEmployee($this->employee);
        $this->postJson("/api/hr/me/leave/requests/{$id}/cancel")->assertOk();
        $this->assertSame(10.0, $this->balanceOf($this->employee, $this->annual));

        $started = $this->submit(['starts_on' => '2026-03-02', 'ends_on' => '2026-03-03'])->json('data.id');
        $this->approveAsManager($started);
        $this->actingAsEmployee($this->employee);
        $this->postJson("/api/hr/me/leave/requests/{$started}/cancel")
            ->assertUnprocessable()->assertJsonPath('message', __('hr::leave.already_started'));
    }

    public function test_another_employees_request_is_not_found(): void
    {
        $id = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->json('data.id');

        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/leave/requests/{$id}/cancel")->assertNotFound();
    }

    public function test_the_quote_shows_the_cost_and_what_would_be_left(): void
    {
        $this->actingAsEmployee($this->employee);

        $this->postJson('/api/hr/me/leave/quote', ['leave_type_id' => $this->annual->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-11'])
            ->assertOk()
            ->assertJsonPath('data', ['amount' => '3', 'unit' => 'day', 'available' => '10', 'remaining_after' => '7']);
    }

    public function test_hr_files_leave_for_an_employee_through_the_same_flow(): void
    {
        $this->actingAsWith([Abilities::LEAVE_MANAGE, Abilities::LEAVE_VIEW]);

        $id = $this->postJson("/api/hr/employees/{$this->employee->id}/leave-requests", [
            'leave_type_id' => $this->annual->id,
            'starts_on' => '2026-03-09',
            'ends_on' => '2026-03-09',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/hr/leave-requests?status=pending')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->approveAsManager($id);
        $this->assertSame(9.0, $this->balanceOf($this->employee, $this->annual));
    }

    public function test_coverage_blocks_an_approval_that_would_empty_the_unit(): void
    {
        $unit = $this->manager->currentAssignment()->org_unit_id;
        $colleague = $this->reportOf($this->manager);
        CoverageRule::query()->create(['org_unit_id' => $unit, 'min_present' => 2]);
        $this->giveBalance($colleague, $this->annual, 5);

        $first = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'], $colleague)->json('data.id');
        $this->approveAsManager($first);
        $second = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-10'])->json('data.id');

        $approval = LeaveRequest::query()->find($second)->approval_request_id;
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('hr::leave.coverage_breached', ['min' => 2, 'date' => '2026-03-09']));
        $this->assertSame('pending', LeaveRequest::query()->find($second)->status->value);
    }

    public function test_the_team_calendar_shows_direct_reports_and_hides_private_types(): void
    {
        $sick = $this->leaveType(['tracks_balance' => false, 'hide_reason' => true]);
        $this->submit(['leave_type_id' => $sick->id, 'starts_on' => '2026-03-09', 'ends_on' => '2026-03-09', 'reason' => 'flu'])->assertCreated();
        $this->submit(['starts_on' => '2026-03-10', 'ends_on' => '2026-03-10'])->assertCreated();
        $outsider = $this->staff();
        $this->giveBalance($outsider, $this->annual, 5);
        $this->submit(['starts_on' => '2026-03-10', 'ends_on' => '2026-03-10'], $outsider)->assertCreated();

        $this->actingAsEmployee($this->manager);
        $this->getJson('/api/hr/me/team/leave?from=2026-03-01&to=2026-03-31')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.leave_type', null)
            ->assertJsonMissingPath('data.0.reason')
            ->assertJsonPath('data.1.leave_type.id', $this->annual->id);
    }

    public function test_termination_withdraws_pending_and_future_approved_leave(): void
    {
        $approved = $this->submit(['starts_on' => '2026-04-06', 'ends_on' => '2026-04-07'])->json('data.id');
        $this->approveAsManager($approved);
        $pending = $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'])->json('data.id');

        $this->actingAsWith([Abilities::LIFECYCLE_MANAGE]);
        $this->postJson("/api/hr/employees/{$this->employee->id}/events", [
            'type' => 'termination', 'effective_on' => '2026-03-31', 'reason' => 'resignation',
        ])->assertCreated();

        $this->assertSame('cancelled', LeaveRequest::query()->find($approved)->status->value);
        $this->assertSame('cancelled', LeaveRequest::query()->find($pending)->status->value);
        $this->assertSame(10.0, $this->balanceOf($this->employee, $this->annual));
    }

    public function test_hr_reads_the_calendar_for_a_unit(): void
    {
        $other = $this->staff([], ['assignment' => ['org_unit_id' => OrgUnit::factory()->create()->id]]);
        $this->giveBalance($other, $this->annual, 5);
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'])->assertCreated();
        $this->submit(['starts_on' => '2026-03-09', 'ends_on' => '2026-03-09'], $other)->assertCreated();

        $this->actingAsWith([Abilities::LEAVE_VIEW]);
        $unit = $this->employee->currentAssignment()->org_unit_id;
        $this->getJson("/api/hr/leave-calendar?from=2026-03-01&to=2026-03-31&org_unit_id={$unit}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.id', $this->employee->id);
    }
}
