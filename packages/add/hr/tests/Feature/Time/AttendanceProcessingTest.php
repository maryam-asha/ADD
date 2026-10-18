<?php

namespace Add\Hr\Tests\Feature\Time;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Services\LeaveService;
use Add\Hr\Support\HrException;
use Add\Hr\Tests\Concerns\BuildsSchedules;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Models\Punch;
use Add\Hr\Time\Models\RosterEntry;
use Add\Hr\Time\Models\TimePeriod;
use Add\Hr\Time\Services\AttendanceCards;
use Add\Hr\Time\Services\ExceptionReview;

/**
 * Monday 2 March 2026 is the work day under test; "now" is Tuesday 3 March,
 * 10:00 in Damascus. Shift 08:00–16:00 with a 30-minute break (450 planned
 * minutes), grace 10 minutes, overtime from 30 minutes.
 */
class AttendanceProcessingTest extends TestCase
{
    use BuildsSchedules;
    use HiresStaff;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalTimezone();
        $this->travelTo('2026-03-03 07:00:00');

        $this->employee = $this->staff([], ['assignment' => ['work_pattern_id' => $this->fixedPattern($this->shift())->id]]);
    }

    public function test_an_on_time_day_is_present_with_its_worked_minutes(): void
    {
        $this->punchAt($this->employee, '2026-03-02 07:58');
        $this->punchAt($this->employee, '2026-03-02 12:00');
        $this->punchAt($this->employee, '2026-03-02 16:05');

        $day = $this->dayOf($this->employee, '2026-03-02');
        $this->assertSame('present', $day->status->value);
        $this->assertSame(3, $day->punch_count);
        $this->assertSame(487 - 30, $day->worked_minutes);
        $this->assertSame(0, $day->late_minutes);
        $this->assertSame(0, $day->overtime_minutes);
        $this->assertSame([], $this->exceptionTypes($day));
        $this->assertSame('2026-03-02 05:00:00', $day->scheduled_start->utc()->format('Y-m-d H:i:s'));
    }

    public function test_lateness_early_leave_and_overtime_count_beyond_the_policy(): void
    {
        $other = $this->staff([], ['assignment' => ['work_pattern_id' => $this->employee->currentAssignment()->work_pattern_id]]);

        $this->punchAt($this->employee, '2026-03-02 08:25');
        $this->punchAt($this->employee, '2026-03-02 15:30');
        $this->punchAt($other, '2026-03-02 08:09');
        $this->punchAt($other, '2026-03-02 17:00');

        $late = $this->dayOf($this->employee, '2026-03-02');
        $this->assertSame(25, $late->late_minutes);
        $this->assertSame(30, $late->early_leave_minutes);
        $this->assertSame(['early_leave', 'late'], $this->exceptionTypes($late));

        $long = $this->dayOf($other, '2026-03-02');
        $this->assertSame(0, $long->late_minutes);
        $this->assertSame(531 - 30 - 450, $long->overtime_minutes);
        $this->assertSame([], $this->exceptionTypes($long));
    }

    public function test_a_second_scan_within_a_minute_and_a_resend_are_the_same_punch(): void
    {
        $first = $this->punchAt($this->employee, '2026-03-02 08:00', key: 'device-1');
        $twin = $this->punchAt($this->employee, '2026-03-02 08:00:40');
        $resend = $this->punchAt($this->employee, '2026-03-02 08:00', key: 'device-1');

        $this->assertFalse($first->duplicate);
        $this->assertTrue($twin->duplicate);
        $this->assertTrue($resend->duplicate);
        $this->assertSame(1, Punch::query()->count());
    }

    public function test_a_single_punch_is_incomplete_once_the_day_is_over_and_open_while_it_runs(): void
    {
        $this->punchAt($this->employee, '2026-03-02 08:00');
        $this->punchAt($this->employee, '2026-03-03 08:00');

        $this->assertSame('incomplete', $this->dayOf($this->employee, '2026-03-02')->status->value);
        $this->assertSame(['missing_out'], $this->exceptionTypes($this->dayOf($this->employee, '2026-03-02')));
        $this->assertSame('open', $this->dayOf($this->employee, '2026-03-03')->status->value);
        $this->assertSame([], $this->exceptionTypes($this->dayOf($this->employee, '2026-03-03')));
    }

    public function test_a_day_without_punches_needs_review_and_the_reviewer_decides_it(): void
    {
        $this->artisan('hr:attendance-process')->assertSuccessful();

        $day = $this->dayOf($this->employee, '2026-03-02');
        $this->assertSame('no_record', $day->status->value);
        $this->assertSame(['no_record'], $this->exceptionTypes($day));
        // Sunday is a working day of the pattern too.
        $this->assertSame('no_record', $this->dayOf($this->employee, '2026-03-01')->status->value);

        app(ExceptionReview::class)->resolve($day->exceptions->first(), ExceptionResolution::Absent, 'no show', null);
        $this->assertSame('absent', $this->dayOf($this->employee, '2026-03-02')->status->value);

        // Rebuilding keeps the reviewer's decision.
        $this->artisan('hr:attendance-process')->assertSuccessful();
        $this->assertSame('absent', $this->dayOf($this->employee, '2026-03-02')->status->value);
    }

    public function test_leave_approved_after_the_fact_turns_missing_days_into_leave(): void
    {
        $this->artisan('hr:attendance-process')->assertSuccessful();
        $this->assertSame('no_record', $this->dayOf($this->employee, '2026-03-02')->status->value);

        $sick = LeaveType::query()->create(['code' => 'sick', 'name' => ['ar' => 'مرضية'], 'tracks_balance' => false]);
        $request = app(LeaveService::class)->submit($this->employee, [
            'leave_type_id' => $sick->id, 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-02',
        ], null, $this->employee->user_id);
        app(ApprovalEngine::class)->approve($request->approval, $this->actingAsWith([Abilities::APPROVALS_MANAGE]), override: true);

        $day = $this->dayOf($this->employee, '2026-03-02');
        $this->assertSame('leave', $day->status->value);
        $this->assertSame([], $this->exceptionTypes($day));
    }

    public function test_a_night_shift_keeps_its_morning_punch(): void
    {
        $night = $this->shift('22:00', '06:00', 0);
        $worker = $this->staff([], ['assignment' => ['work_pattern_id' => $this->fixedPattern($night, [1])->id]]);

        $this->punchAt($worker, '2026-03-02 21:55');
        $this->punchAt($worker, '2026-03-03 06:10');

        $day = $this->dayOf($worker, '2026-03-02');
        $this->assertSame('present', $day->status->value);
        $this->assertSame(495, $day->worked_minutes);
        $this->assertSame([], $this->exceptionTypes($day));
        $this->assertSame(0, $this->dayOf($worker, '2026-03-03')->punch_count);
    }

    public function test_work_on_a_rest_day_is_flagged_and_counted_as_overtime(): void
    {
        // Friday 6 March is a rest day in the pattern.
        $this->travelTo('2026-03-08 07:00:00');
        $this->punchAt($this->employee, '2026-03-06 10:00');
        $this->punchAt($this->employee, '2026-03-06 14:00');

        $day = $this->dayOf($this->employee, '2026-03-06');
        $this->assertSame('present', $day->status->value);
        $this->assertSame(240, $day->overtime_minutes);
        $this->assertSame(['unscheduled_work'], $this->exceptionTypes($day));
    }

    public function test_a_roster_entry_overrides_the_pattern(): void
    {
        RosterEntry::query()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'shift_template_id' => null]);

        $this->artisan('hr:attendance-process')->assertSuccessful();

        $this->assertSame('rest', $this->dayOf($this->employee, '2026-03-02')->status->value);
    }

    public function test_attendance_is_not_tracked_for_someone_without_a_plan_who_never_punches(): void
    {
        $unplanned = $this->staff();

        $this->artisan('hr:attendance-process')->assertSuccessful();
        $this->assertNull($this->dayOf($unplanned, '2026-03-02'));

        $this->punchAt($unplanned, '2026-03-02 09:00');
        $this->punchAt($unplanned, '2026-03-02 17:00');
        $this->assertSame('present', $this->dayOf($unplanned, '2026-03-02')->status->value);
    }

    public function test_punches_are_refused_outside_employment_and_in_a_locked_month(): void
    {
        TimePeriod::query()->create(['year' => 2026, 'month' => 2, 'status' => 'locked']);

        $this->expectException(HrException::class);
        $this->punchAt($this->employee, '2026-02-26 08:00');
    }

    public function test_cards_resolve_to_their_employee_until_replaced(): void
    {
        $cards = app(AttendanceCards::class);
        $old = $cards->issue($this->employee, null);
        $new = $cards->issue($this->employee, null);

        $this->assertStringStartsWith('hr-card:v1:', $new);
        $this->assertNull($cards->resolve($old));
        $this->assertSame($this->employee->id, $cards->resolve($new)->id);
        $this->assertNull($cards->resolve('hr-card:v1:forged'));

        $cards->revoke($this->employee);
        $this->assertNull($cards->resolve($new));
    }
}
