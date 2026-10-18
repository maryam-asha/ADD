<?php

namespace Add\Hr\Tests\Feature\Timesheets;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Models\TimePeriod;
use Add\Hr\Time\Services\PunchRecorder;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Models\Timesheet;
use Add\Hr\Timesheets\Services\CostAllocationService;
use Add\Hr\Timesheets\Services\CostDistribution;
use Carbon\CarbonImmutable;

/**
 * Weeks start on Monday (the package default); "now" is Wednesday 4 March
 * 2026, so the week under test starts on Monday 2 March.
 */
class TimesheetTest extends TestCase
{
    use HiresStaff;

    private Employee $manager;

    private Employee $employee;

    private CostCenter $ops;

    private CostCenter $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-04 12:00:00');

        $this->manager = $this->staff();
        $this->employee = $this->reportOf($this->manager);
        $this->ops = CostCenter::query()->create(['code' => 'ops', 'name' => ['ar' => 'التشغيل']]);
        $this->lab = CostCenter::query()->create(['code' => 'lab', 'name' => ['ar' => 'المختبر']]);
    }

    private function fill(array $entries, ?Employee $as = null)
    {
        $this->actingAsEmployee($as ?? $this->employee);

        return $this->putJson('/api/hr/me/timesheets/2026-03-02', ['entries' => $entries]);
    }

    private function line(CostCenter $center, string $date, float $hours): array
    {
        return ['cost_center_id' => $center->id, 'work_date' => $date, 'hours' => $hours];
    }

    public function test_a_week_is_filled_and_shown_beside_attendance(): void
    {
        $recorder = app(PunchRecorder::class);
        $recorder->record($this->employee, CarbonImmutable::parse('2026-03-02 08:00'), PunchSource::CardScan);
        $recorder->record($this->employee, CarbonImmutable::parse('2026-03-02 16:00'), PunchSource::CardScan);

        $this->fill([$this->line($this->ops, '2026-03-02', 5), $this->line($this->lab, '2026-03-02', 2.5)])
            ->assertOk()->assertJsonPath('message', __('hr::timesheets.saved'));

        $this->getJson('/api/hr/me/timesheets/2026-03-02')->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_hours', '7.5')
            ->assertJsonCount(2, 'data.entries')
            ->assertJsonPath('reconciliation.days.0.timesheet_hours', '7.5')
            ->assertJsonPath('reconciliation.days.0.attendance_hours', '8')
            ->assertJsonPath('reconciliation.days.0.difference_hours', '-0.5')
            ->assertJsonPath('reconciliation.difference_hours', -0.5);

        $this->getJson('/api/hr/me/timesheets/2026-03-09')->assertOk()->assertJsonPath('data.id', null)->assertJsonPath('data.entries', []);
        $this->getJson('/api/hr/me/timesheets/2026-03-03')->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.not_a_week_start'));
    }

    public function test_lines_are_checked(): void
    {
        $this->fill([$this->line($this->ops, '2026-03-02', 1.25)])->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.increment', ['step' => 0.5]));
        $this->fill([$this->line($this->ops, '2026-03-09', 1)])->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.outside_week'));
        $this->fill([$this->line($this->ops, '2026-03-02', 20), $this->line($this->lab, '2026-03-02', 5)])->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.day_over_24'));

        $this->lab->update(['is_active' => false]);
        $this->fill([$this->line($this->lab, '2026-03-02', 1)])->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.cost_center_inactive'));

        TimePeriod::query()->create(['year' => 2026, 'month' => 3, 'status' => 'locked']);
        $this->fill([$this->line($this->ops, '2026-03-02', 1)])->assertUnprocessable();
    }

    public function test_submit_approve_reject_and_recall(): void
    {
        $this->fill([$this->line($this->ops, '2026-03-02', 8)])->assertOk();
        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')->assertOk();
        $this->fill([$this->line($this->ops, '2026-03-02', 7)])->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.not_editable'));

        $this->postJson('/api/hr/me/timesheets/2026-03-02/recall')->assertOk();
        $this->assertSame('draft', Timesheet::query()->sole()->status->value);

        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')->assertOk();
        $approval = Timesheet::query()->sole()->approval_request_id;
        $this->actingAsEmployee($this->manager);
        $this->getJson('/api/hr/me/approvals')->assertOk()->assertJsonPath('data.0.subject.total_hours', '8');
        $this->postJson("/api/hr/me/approvals/{$approval}/reject", ['comment' => 'Split the lab hours'])->assertOk();
        $this->assertSame('rejected', Timesheet::query()->sole()->status->value);

        $this->fill([$this->line($this->ops, '2026-03-02', 5), $this->line($this->lab, '2026-03-02', 3)])->assertOk();
        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')->assertOk();
        $approval = Timesheet::query()->sole()->approval_request_id;
        $this->actingAsEmployee($this->manager);
        $this->postJson("/api/hr/me/approvals/{$approval}/approve")->assertOk();
        $this->assertSame('approved', Timesheet::query()->sole()->status->value);
    }

    public function test_a_variance_limit_refuses_a_week_too_far_from_attendance(): void
    {
        config(['hr.timesheets.max_variance_hours' => 1]);

        $this->fill([$this->line($this->ops, '2026-03-02', 8)])->assertOk();
        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')
            ->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.variance_too_large', ['hours' => 1]));
    }

    public function test_compliance_lists_who_has_not_handed_in_the_week(): void
    {
        $this->fill([$this->line($this->ops, '2026-03-02', 8)])->assertOk();
        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')->assertOk();

        $this->actingAsWith([Abilities::TIMESHEETS_VIEW]);
        $this->getJson('/api/hr/timesheets/compliance?week_start=2026-03-02')->assertOk()
            ->assertJsonCount(1, 'data.missing')
            ->assertJsonPath('data.missing.0.id', $this->manager->id);
        $this->getJson('/api/hr/timesheets?status=submitted')->assertOk()->assertJsonPath('data.0.employee.id', $this->employee->id);
    }

    public function test_cost_splits_are_effective_dated_and_add_up_to_100(): void
    {
        $this->actingAsWith([Abilities::TIMESHEETS_MANAGE, Abilities::TIMESHEETS_VIEW]);
        $url = "/api/hr/employees/{$this->employee->id}/cost-allocations";

        $this->putJson($url, ['effective_from' => '2026-01-01', 'lines' => [['cost_center_id' => $this->ops->id, 'percent' => 60]]])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.allocation_not_100'));
        $this->putJson($url, ['effective_from' => '2026-01-01', 'lines' => [['cost_center_id' => $this->ops->id, 'percent' => 100]]])->assertOk();
        $this->putJson($url, ['effective_from' => '2026-03-01', 'lines' => [
            ['cost_center_id' => $this->ops->id, 'percent' => 70],
            ['cost_center_id' => $this->lab->id, 'percent' => 30],
        ]])->assertOk();
        $this->putJson($url, ['effective_from' => '2026-02-01', 'lines' => [['cost_center_id' => $this->ops->id, 'percent' => 100]]])
            ->assertUnprocessable()->assertJsonPath('message', __('hr::timesheets.allocation_date_invalid'));

        $this->getJson($url)->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.effective_to', '2026-02-28')
            ->assertJsonPath('data.2.percent', '30');
    }

    public function test_cost_follows_approved_hours_else_the_planned_split(): void
    {
        $distribution = app(CostDistribution::class);
        app(CostAllocationService::class)->replace($this->employee, CarbonImmutable::parse('2026-03-01'), [
            ['cost_center_id' => $this->ops->id, 'percent' => 75],
            ['cost_center_id' => $this->lab->id, 'percent' => 25],
        ], null);
        $march = [CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')];

        $this->assertSame([$this->ops->id => 0.75, $this->lab->id => 0.25], $distribution->sharesFor($this->employee, ...$march));
        $this->assertSame([], $distribution->sharesFor($this->manager, ...$march));

        $this->fill([$this->line($this->ops, '2026-03-02', 2), $this->line($this->lab, '2026-03-03', 6)])->assertOk();
        $this->postJson('/api/hr/me/timesheets/2026-03-02/submit')->assertOk();
        $this->actingAsEmployee($this->manager);
        $this->postJson('/api/hr/me/approvals/'.Timesheet::query()->sole()->approval_request_id.'/approve')->assertOk();

        $this->assertSame([$this->ops->id => 0.25, $this->lab->id => 0.75], $distribution->sharesFor($this->employee, ...$march));

        $this->actingAsWith([Abilities::TIMESHEETS_VIEW]);
        $this->getJson('/api/hr/reports/time-by-cost-center?from=2026-03-01&to=2026-03-31')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.cost_center.code', 'lab')
            ->assertJsonPath('data.0.hours', '6');
        $csv = $this->get('/api/hr/reports/time-by-cost-center?from=2026-03-01&to=2026-03-31&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('cost_center_code,cost_center,employee_number,employee,hours', $csv);
        $this->assertStringContainsString('lab,', $csv);
    }

    public function test_cost_centers_are_settings_and_stay_once_used(): void
    {
        $this->actingAsWith([Abilities::TIMESHEETS_VIEW]);
        $this->postJson('/api/hr/cost-centers', ['code' => 'x', 'name' => ['ar' => 'س']])->assertForbidden();

        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);
        $this->postJson('/api/hr/cost-centers', ['code' => 'grant-eu', 'name' => ['ar' => 'منحة'], 'costable_type' => 'project', 'costable_id' => 7])
            ->assertCreated()->assertJsonPath('data.costable_id', 7);

        app(CostAllocationService::class)->replace($this->employee, CarbonImmutable::parse('2026-03-01'), [['cost_center_id' => $this->ops->id, 'percent' => 100]], null);
        $this->deleteJson("/api/hr/cost-centers/{$this->ops->id}")->assertStatus(409);
        $this->deleteJson("/api/hr/cost-centers/{$this->lab->id}")->assertNoContent();
    }
}
