<?php

namespace Add\Hr\Tests\Feature\Time;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Leave\Models\Holiday;
use Add\Hr\Tests\Concerns\BuildsLeave;
use Add\Hr\Tests\Concerns\BuildsSchedules;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Time\Models\RosterEntry;
use Add\Hr\Time\Models\TimePeriod;

/**
 * "Now" is Monday 2 March 2026, morning in Damascus.
 */
class ScheduleAndRosterTest extends TestCase
{
    use BuildsLeave;
    use BuildsSchedules;
    use HiresStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useLocalTimezone();
        $this->travelTo('2026-03-02 06:00:00');
    }

    public function test_shifts_and_patterns_are_managed_and_validated(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE, Abilities::ATTENDANCE_VIEW]);

        $night = $this->postJson('/api/hr/shift-templates', ['code' => 'night', 'name' => ['ar' => 'ليلي'], 'starts_at' => '22:00', 'ends_at' => '06:00'])
            ->assertCreated()
            ->assertJsonPath('data.crosses_midnight', true)
            ->assertJsonPath('data.planned_minutes', 480)
            ->json('data.id');

        $this->postJson('/api/hr/work-patterns', ['code' => 'week', 'name' => ['ar' => 'أسبوعي'], 'kind' => 'fixed'])
            ->assertUnprocessable()->assertJsonValidationErrors('weekly');

        $this->postJson('/api/hr/work-patterns', ['code' => 'nights', 'name' => ['ar' => 'ليالي'], 'kind' => 'fixed', 'weekly' => ['1' => $night, '3' => $night]])
            ->assertCreated()
            ->assertJsonPath('data.weekly', ['1' => $night, '2' => null, '3' => $night, '4' => null, '5' => null, '6' => null, '7' => null]);

        $this->deleteJson("/api/hr/shift-templates/{$night}")->assertStatus(409);

        $this->actingAsWith([Abilities::ATTENDANCE_VIEW]);
        $this->postJson('/api/hr/shift-templates', ['code' => 'x', 'name' => ['ar' => 'س'], 'starts_at' => '08:00', 'ends_at' => '16:00'])->assertForbidden();
    }

    public function test_my_schedule_follows_pattern_holidays_roster_and_leave(): void
    {
        $day = $this->shift();
        $employee = $this->staff([], ['assignment' => ['work_pattern_id' => $this->fixedPattern($day)->id]]);
        Holiday::query()->create(['name' => ['ar' => 'عطلة'], 'starts_on' => '2026-03-03', 'ends_on' => '2026-03-03']);
        RosterEntry::query()->create(['employee_id' => $employee->id, 'work_date' => '2026-03-04', 'shift_template_id' => null]);

        $this->actingAsEmployee($employee);
        $this->getJson('/api/hr/me/schedule?from=2026-03-02&to=2026-03-06')->assertOk()
            ->assertJsonPath('data.0.kind', 'shift')
            ->assertJsonPath('data.0.shift.starts_at', '2026-03-02T08:00:00+03:00')
            ->assertJsonPath('data.1.kind', 'holiday')
            ->assertJsonPath('data.2.kind', 'rest')
            ->assertJsonPath('data.3.kind', 'shift')
            ->assertJsonPath('data.4.kind', 'rest');
    }

    public function test_the_roster_is_saved_copied_and_shown_as_a_grid(): void
    {
        $day = $this->shift();
        $manager = $this->staff();
        $employee = $this->reportOf($manager, ['assignment' => ['work_pattern_id' => $this->fixedPattern($day)->id]]);
        $unit = $employee->currentAssignment()->org_unit_id;
        $this->actingAsWith([Abilities::ROSTER_MANAGE, Abilities::ATTENDANCE_VIEW]);

        $this->putJson('/api/hr/roster', ['entries' => [
            ['employee_id' => $employee->id, 'work_date' => '2026-03-06', 'shift_template_id' => $day->id],
            ['employee_id' => $employee->id, 'work_date' => '2026-03-02', 'shift_template_id' => null],
        ]])->assertOk()->assertJsonPath('message', __('hr::time.roster_saved'));

        $this->postJson('/api/hr/roster/copy-week', ['from' => '2026-03-01', 'to' => '2026-03-08', 'org_unit_id' => $unit])
            ->assertOk()->assertJsonPath('message', __('hr::time.roster_copied', ['count' => 2]));
        $this->assertTrue(RosterEntry::query()->where('employee_id', $employee->id)->where('work_date', '2026-03-13')->exists());

        $this->getJson("/api/hr/roster?from=2026-03-02&to=2026-03-06&org_unit_id={$unit}")->assertOk()
            ->assertJsonPath('data.1.employee.id', $employee->id)
            ->assertJsonPath('data.1.days.0.kind', 'rest')
            ->assertJsonPath('data.1.days.0.rostered', true)
            ->assertJsonPath('data.1.days.1.kind', 'shift')
            ->assertJsonPath('data.1.days.1.rostered', false)
            ->assertJsonPath('data.1.days.4.kind', 'shift');

        $this->putJson('/api/hr/roster', ['entries' => [['employee_id' => $employee->id, 'work_date' => '2026-03-02', 'remove' => true]]])->assertOk();
        $this->assertFalse(RosterEntry::query()->where('employee_id', $employee->id)->where('work_date', '2026-03-02')->exists());
    }

    public function test_a_locked_month_refuses_roster_changes(): void
    {
        $employee = $this->staff();
        TimePeriod::query()->create(['year' => 2026, 'month' => 2, 'status' => 'locked']);
        $this->actingAsWith([Abilities::ROSTER_MANAGE]);

        $this->putJson('/api/hr/roster', ['entries' => [['employee_id' => $employee->id, 'work_date' => '2026-02-20', 'shift_template_id' => null]]])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::time.period_locked', ['month' => '2026-02']));
    }

    public function test_leave_counts_the_employees_own_working_days(): void
    {
        // Works Friday and Saturday (ISO 5, 6) — the package's default weekend.
        $employee = $this->staff([], ['assignment' => ['work_pattern_id' => $this->fixedPattern($this->shift(), [5, 6])->id]]);
        $annual = $this->leaveType();
        $this->policy($annual);
        $this->giveBalance($employee, $annual, 10);

        $this->actingAsEmployee($employee);
        $this->postJson('/api/hr/me/leave/quote', ['leave_type_id' => $annual->id, 'starts_on' => '2026-03-02', 'ends_on' => '2026-03-08'])
            ->assertOk()->assertJsonPath('data.amount', '2');
    }
}
