<?php

namespace Add\Hr\Tests\Feature\Time;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Tests\Concerns\BuildsSchedules;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Time\Models\AttendancePolicy;
use Add\Hr\Time\Models\Punch;
use Add\Hr\Time\Models\TimePeriod;

/**
 * "Now" is Tuesday 3 March 2026, 10:00 in Damascus; Monday 2 March is a
 * working day (08:00–16:00).
 */
class EntriesOvertimeAndLockTest extends TestCase
{
    use BuildsSchedules;
    use HiresStaff;

    private Employee $manager;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalTimezone();
        $this->travelTo('2026-03-03 07:00:00');

        $this->manager = $this->staff();
        $this->employee = $this->reportOf($this->manager, ['assignment' => ['work_pattern_id' => $this->fixedPattern($this->shift())->id]]);
    }

    private function approveAsManager(int $approvalId): void
    {
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approvalId}/approve")->assertOk();
    }

    public function test_my_times_are_verified_by_nearby_card_scans(): void
    {
        $this->punchAt($this->employee, '2026-03-02 08:03');
        $this->punchAt($this->employee, '2026-03-02 16:01');

        $this->actingAsEmployee($this->employee);
        $this->postJson('/api/hr/me/attendance/entries', ['work_date' => '2026-03-02', 'check_in' => '08:00', 'check_out' => '16:10'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.approval_request_id', null)
            ->assertJsonPath('data.check_in_at', '2026-03-02T08:00:00+03:00');
    }

    public function test_times_no_scan_confirms_go_to_the_manager_and_become_punches_when_approved(): void
    {
        $this->punchAt($this->employee, '2026-03-02 08:00');

        $this->actingAsEmployee($this->employee);
        $approval = $this->postJson('/api/hr/me/attendance/entries', ['work_date' => '2026-03-02', 'check_out' => '16:30', 'note' => 'Forgot my card'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.approval_request_id');

        $this->postJson('/api/hr/me/attendance/entries', ['work_date' => '2026-03-02', 'check_out' => '16:40'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.entry_pending'));

        $this->approveAsManager($approval);

        $this->assertSame('approved', AttendanceEntry::query()->sole()->status->value);
        $this->assertSame(['card_scan', 'manual_entry'], Punch::query()->orderBy('punched_at')->get()->map(fn ($p) => $p->source->value)->all());
        $day = $this->dayOf($this->employee, '2026-03-02');
        $this->assertSame('present', $day->status->value);
        $this->assertSame([], $this->exceptionTypes($day));
    }

    public function test_a_rejected_entry_adds_nothing_and_future_days_are_refused(): void
    {
        $this->actingAsEmployee($this->employee);
        $approval = $this->postJson('/api/hr/me/attendance/entries', ['work_date' => '2026-03-02', 'check_in' => '08:00', 'check_out' => '16:00'])
            ->json('data.approval_request_id');
        $this->postJson('/api/hr/me/attendance/entries', ['work_date' => '2026-03-05', 'check_in' => '08:00'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.entry_in_future'));

        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/reject")->assertOk();

        $this->assertSame('rejected', AttendanceEntry::query()->sole()->status->value);
        $this->assertSame(0, Punch::query()->count());
    }

    public function test_overtime_after_the_fact_fits_in_what_attendance_recorded(): void
    {
        $this->punchAt($this->employee, '2026-03-02 08:00');
        $this->punchAt($this->employee, '2026-03-02 17:30');
        // 570 - 30 break - 450 planned = 90 overtime minutes.

        $this->actingAsEmployee($this->employee);
        $this->postJson('/api/hr/me/overtime', ['work_date' => '2026-03-02', 'minutes' => 120, 'reason' => 'Event setup'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.overtime_exceeds_recorded', ['minutes' => 90]));
        $approval = $this->postJson('/api/hr/me/overtime', ['work_date' => '2026-03-02', 'minutes' => 90, 'reason' => 'Event setup'])
            ->assertCreated()->assertJsonPath('data.kind', 'post')->json('data.approval_request_id');

        $this->approveAsManager($approval);
        $this->actingAsEmployee($this->employee);
        $this->getJson('/api/hr/me/overtime')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_a_policy_can_require_overtime_to_be_approved_ahead(): void
    {
        AttendancePolicy::query()->create(['effective_from' => '2026-01-01', 'overtime_requires_pre_approval' => true]);
        $this->actingAsEmployee($this->employee);

        $this->postJson('/api/hr/me/overtime', ['work_date' => '2026-03-02', 'minutes' => 30, 'reason' => 'Late'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.overtime_needs_pre_approval'));
        $this->postJson('/api/hr/me/overtime', ['work_date' => '2026-03-04', 'minutes' => 120, 'reason' => 'Inventory'])
            ->assertCreated()->assertJsonPath('data.kind', 'pre');
    }

    public function test_a_month_locks_only_once_everything_in_it_is_decided(): void
    {
        $this->travelTo('2026-04-02 07:00:00');
        $this->actingAsWith([Abilities::ATTENDANCE_LOCK, Abilities::ATTENDANCE_CORRECT, Abilities::ATTENDANCE_VIEW]);

        $this->postJson('/api/hr/time-periods/2026/4/lock')->assertUnprocessable()->assertJsonPath('message', __('hr::time.period_not_ended'));
        // Every working day of March (Sunday to Thursday) had no punch; the
        // refused lock leaves them to review.
        $this->postJson('/api/hr/time-periods/2026/3/lock')
            ->assertUnprocessable()
            ->assertJsonPath('message', __('hr::time.period_has_open_exceptions', ['count' => 23]));

        foreach (AttendanceException::query()->where('status', 'open')->get() as $exception) {
            $this->postJson("/api/hr/attendance-exceptions/{$exception->id}/resolve", ['resolution' => 'excused', 'note' => 'Remote week'])->assertOk();
        }

        $this->postJson('/api/hr/time-periods/2026/3/lock')->assertOk();
        $this->getJson('/api/hr/time-periods')->assertOk()->assertJsonPath('data.0.status', 'locked');

        $this->postJson("/api/hr/employees/{$this->employee->id}/attendance-punches", ['punched_at' => '2026-03-10 08:00', 'reason' => 'late fix'])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.period_locked', ['month' => '2026-03']));

        $this->postJson('/api/hr/time-periods/2026/3/unlock')->assertOk();
        $this->assertSame('open', TimePeriod::query()->sole()->status->value);
    }

    public function test_hr_adds_a_missed_punch_with_a_reason_and_the_exception_clears(): void
    {
        $this->punchAt($this->employee, '2026-03-02 08:00');
        $this->assertSame(['missing_out'], $this->exceptionTypes($this->dayOf($this->employee, '2026-03-02')));

        $this->actingAsWith([Abilities::ATTENDANCE_VIEW]);
        $this->postJson("/api/hr/employees/{$this->employee->id}/attendance-punches", ['punched_at' => '2026-03-02 16:00', 'reason' => 'x'])->assertForbidden();

        $this->actingAsWith([Abilities::ATTENDANCE_CORRECT, Abilities::ATTENDANCE_VIEW]);
        $this->postJson("/api/hr/employees/{$this->employee->id}/attendance-punches", ['punched_at' => '2026-03-02 16:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/hr/employees/{$this->employee->id}/attendance-punches", ['punched_at' => '2026-03-02 16:00', 'reason' => 'Card reader down'])
            ->assertCreated()->assertJsonPath('data.source', 'manual')->assertJsonPath('data.punched_at', '2026-03-02T16:00:00+03:00');

        $this->getJson('/api/hr/attendance-days?from=2026-03-02&to=2026-03-02')->assertOk()
            ->assertJsonPath('data.0.status', 'present')
            ->assertJsonPath('data.0.exceptions', []);
        $this->getJson("/api/hr/employees/{$this->employee->id}/attendance-punches?from=2026-03-02&to=2026-03-02")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_cards_are_issued_once_and_shown_without_their_value(): void
    {
        $this->actingAsWith([Abilities::ATTENDANCE_CARDS, Abilities::ATTENDANCE_VIEW]);

        $value = $this->postJson("/api/hr/employees/{$this->employee->id}/attendance-card")->assertCreated()->json('data.qr_value');
        $this->assertStringStartsWith('hr-card:v1:', $value);

        $this->getJson("/api/hr/employees/{$this->employee->id}/attendance-card")->assertOk()
            ->assertJsonPath('data.has_card', true)
            ->assertJsonMissingPath('data.qr_value');
    }

    public function test_the_manager_sees_the_teams_day(): void
    {
        $this->travelTo('2026-03-02 07:00:00');
        $this->punchAt($this->employee, '2026-03-02 08:20');

        $this->actingAsEmployee($this->manager);
        $this->getJson('/api/hr/me/team/attendance')->assertOk()
            ->assertJsonPath('data.0.employee.id', $this->employee->id)
            ->assertJsonPath('data.0.plan', 'shift')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.first_in', '2026-03-02T08:20:00+03:00')
            ->assertJsonPath('data.0.late_minutes', 20);
    }
}
