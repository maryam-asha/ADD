<?php

namespace Add\Hr\Tests\Feature\Reports;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Tests\Concerns\HiresStaff;
use Add\Hr\Tests\TestCase;

/**
 * The dashboard and the combined reports: headcount and turnover by month,
 * timesheet compliance, CSV downloads, and pay shown only to payroll readers.
 */
class ReportsTest extends TestCase
{
    use HiresStaff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-06-15 09:00:00');

        // Two employed for a year, one leaving on 10 June, one hired on 3 June.
        $this->staff();
        $this->staff()->update(['terminated_on' => '2026-06-10']);
        $this->hireEmployee(['hired_on' => '2026-06-03']);
    }

    public function test_headcount_and_turnover_by_month(): void
    {
        $this->actingAs($this->user([Abilities::REPORTS_VIEW]));

        $byUnit = $this->getJson('/api/hr/reports/headcount?from=2026-05-01&to=2026-06-30')->assertOk()
            ->assertJsonPath('data.months.0', ['month' => '2026-05', 'opening' => 2, 'hires' => 0, 'leavers' => 0, 'closing' => 2, 'turnover_percent' => '0'])
            ->assertJsonPath('data.months.1', ['month' => '2026-06', 'opening' => 2, 'hires' => 1, 'leavers' => 1, 'closing' => 2, 'turnover_percent' => '50'])
            ->json('data.by_unit');
        $this->assertSame(2, collect($byUnit)->sum('headcount'));

        $csv = $this->get('/api/hr/reports/headcount?from=2026-05-01&to=2026-06-30&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString("month,opening,hires,leavers,closing,turnover_percent\n2026-05,2,0,0,2,0\n2026-06,2,1,1,2,50", $csv);

        $this->getJson('/api/hr/reports/timesheet-compliance?week_start=2026-06-08')->assertOk()
            ->assertJsonPath('data.summary', ['missing' => 3]);
    }

    public function test_the_dashboard_reads_alerts_on_the_organisations_calendar_day(): void
    {
        // The application runs in UTC and the organisation at +03:00, whose
        // midnight is 21:00 UTC the day before; 10:00 on 15 June locally.
        config(['hr.timezone' => 'Asia/Damascus']);
        $this->travelTo('2026-06-15 07:00:00');
        // A probation ending on the last day of the 21-day window.
        $this->hireEmployee(['contract' => ['probation_ends_on' => '2026-07-06']]);
        $this->actingAs($this->user([Abilities::REPORTS_VIEW]));

        $named = $this->getJson('/api/hr/reports/dashboard?as_of=2026-06-15')->assertOk()
            ->assertJsonPath('data.alerts.by_type.probation_ending', 1)->json('data.alerts');
        $this->getJson('/api/hr/reports/dashboard')->assertOk()
            ->assertJsonPath('data.as_of', '2026-06-15')
            ->assertJsonPath('data.alerts', $named);
    }

    public function test_the_dashboard_shows_pay_only_to_payroll_readers(): void
    {
        $this->actingAs($this->user([Abilities::REPORTS_VIEW]));
        $this->getJson('/api/hr/reports/dashboard')->assertOk()
            ->assertJsonPath('data.headcount.employed', 2)
            ->assertJsonPath('data.headcount.hires_this_month', 1)
            ->assertJsonPath('data.headcount.leavers_this_month', 1)
            ->assertJsonPath('data.recruitment.open_requisitions', 0)
            ->assertJsonMissingPath('data.payroll');
        $this->getJson('/api/hr/reports/liabilities')->assertOk()->assertJsonPath('data.loans', null);

        $this->actingAs($this->user([Abilities::REPORTS_VIEW, Abilities::PAYROLL_VIEW]));
        $this->getJson('/api/hr/reports/dashboard')->assertOk()->assertJsonPath('data.payroll.latest_run', null);
        $this->getJson('/api/hr/reports/liabilities')->assertOk()->assertJsonPath('data.loans', []);

        $this->actingAs($this->user([Abilities::PAYROLL_VIEW]));
        $this->getJson('/api/hr/reports/dashboard')->assertForbidden();
    }
}
