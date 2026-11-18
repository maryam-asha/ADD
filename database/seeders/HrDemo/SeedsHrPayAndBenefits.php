<?php

namespace Database\Seeders\HrDemo;

use Add\Hr\Benefits\Models\Benefit;
use Add\Hr\Benefits\Services\BenefitAssignmentService;
use Add\Hr\Benefits\Services\BenefitGranter;
use Add\Hr\Core\Models\EmploymentEvent;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Services\CompensationService;
use Add\Hr\Payroll\Services\LoanService;
use Add\Hr\Payroll\Services\PayrollCalculator;
use Add\Hr\Payroll\Services\PayrollRunService;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Services\CostAllocationService;
use Add\Hr\Timesheets\Services\TimesheetService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Timesheets (cost centres, a split allocation, weeks approved, rejected,
 * waiting and in draft), pay (everyone's compensation, a raise with a
 * promotion, last month's payroll paid, an off-cycle bonus run being
 * prepared, an active loan and one waiting) and benefits (in-kind credit,
 * an entitlement and a one-time welcome credit, granted or failed).
 */
trait SeedsHrPayAndBenefits
{
    /** @var array<string, CostCenter> */
    private array $costCenters = [];

    private function seedTimesheets(): void
    {
        $center = fn (string $code, string $ar, string $en) => $this->costCenters[$code] = CostCenter::query()->create(['code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'is_active' => true]);
        $center('ops', 'التشغيل', 'Operations');
        $center('lab', 'مختبر ADD', 'ADD Lab');
        $center('grant-eu', 'منحة التحول الرقمي', 'Digital transformation grant');

        app(CostAllocationService::class)->replace($this->employee('developer'), $this->today->startOfMonth()->subMonths(2), [
            ['cost_center_id' => $this->costCenters['lab']->id, 'percent' => 60],
            ['cost_center_id' => $this->costCenters['grant-eu']->id, 'percent' => 40],
        ], $this->hrActor());

        $sheets = app(TimesheetService::class);
        $thisWeek = $this->today->startOfWeek(CarbonInterface::SUNDAY);
        $week = function (string $key, CarbonImmutable $start, array $split) use ($sheets): void {
            $lines = [];
            for ($day = $start; $day->lt($start->addDays(5)); $day = $day->addDay()) {
                if ($day->gte($this->today)) {
                    break;
                }
                foreach ($split as $center => $hours) {
                    $lines[] = ['cost_center_id' => $this->costCenters[$center]->id, 'work_date' => $day->toDateString(), 'hours' => $hours];
                }
            }
            $sheets->save($this->employee($key), $start, $lines);
        };
        $submit = fn (string $key, CarbonImmutable $start) => $sheets->submit($this->employee($key), $start, (int) $this->employee($key)->user_id);

        // The developer: two weeks approved, this one in draft.
        foreach ([2, 1] as $back) {
            $week('developer', $thisWeek->subWeeks($back), ['lab' => 5, 'grant-eu' => 3]);
            $this->approve($submit('developer', $thisWeek->subWeeks($back))->approval_request_id, 'ceo');
        }
        $week('developer', $thisWeek, ['lab' => 4.5, 'grant-eu' => 3.5]);

        // The intern: last week sent back, the week before waiting.
        $week('intern', $thisWeek->subWeeks(2), ['lab' => 4]);
        $submit('intern', $thisWeek->subWeeks(2));
        $week('intern', $thisWeek->subWeek(), ['lab' => 3, 'grant-eu' => 1]);
        $this->approve($submit('intern', $thisWeek->subWeek())->approval_request_id, 'developer', reject: true, comment: 'افصلي ساعات المنحة عن ساعات المختبر بدقة');
    }

    private function seedPayroll(): void
    {
        $t = $this->today;
        $component = fn (string $code) => PayComponent::query()->where('code', $code)->firstOrFail()->id;
        $basicByGrade = ['G1' => 320, 'G2' => 450, 'G3' => 650, 'G4' => 950, 'G5' => 1500];
        $gradeCodes = collect($this->grades)->mapWithKeys(fn ($grade, string $code) => [$grade->id => $code]);

        foreach ($this->staff as $key => $employee) {
            $employee->refresh();
            // Pay starts with the employment, or with the rehire, at that job's grade.
            $from = CarbonImmutable::parse(EmploymentEvent::query()->where('employee_id', $employee->id)->whereIn('type', ['hire', 'rehire'])->max('effective_on'));
            $gradeId = $employee->assignments()->where('effective_from', '<=', $from->toDateString())->orderByDesc('effective_from')->value('job_grade_id');

            $lines = [['pay_component_id' => $component('basic'), 'amount' => $basicByGrade[$gradeCodes[$gradeId] ?? 'G2'], 'currency' => 'USD']];
            if ($key !== 'developer') {
                // Remote: no transport allowance.
                $lines[] = ['pay_component_id' => $component('transport'), 'amount' => 40, 'currency' => 'USD'];
            }

            app(CompensationService::class)->change($employee, $from, $lines, 'hire', $this->hrActor());
        }

        // A raise with the promotion.
        app(CompensationService::class)->change($this->employee('sales_manager'), $t->subMonths(4), [
            ['pay_component_id' => $component('basic'), 'amount' => 950, 'currency' => 'USD'],
            ['pay_component_id' => $component('transport'), 'amount' => 60, 'currency' => 'USD'],
        ], 'promotion', $this->hrActor());

        // Last month: calculated and reviewed by HR, approved by an administrator, paid by finance.
        $last = $t->subMonthNoOverflow();
        $runs = app(PayrollRunService::class);
        $run = $runs->open(['year' => $last->year, 'month' => $last->month, 'kind' => 'regular', 'notes' => 'رواتب '.$last->format('m/Y')], $this->hrActor());
        app(PayrollCalculator::class)->calculate($run, $this->hrActor());
        $runs->review($run->refresh(), $this->hrActor());
        $runs->approve($run->refresh(), $this->admin);
        $runs->pay($run->refresh(), 'BANK-'.$last->format('Ym').'-001', (int) $this->employee('accountant')->user_id);

        // An off-cycle run for a bonus, calculated and waiting for review.
        $bonus = $runs->open(['year' => $last->year, 'month' => $last->month, 'kind' => 'off_cycle', 'employee_ids' => [$this->employee('sales_manager')->id, $this->employee('sales_rep')->id],
            'notes' => 'مكافأة تحقيق هدف المبيعات'], $this->hrActor());
        foreach (['sales_manager' => 300, 'sales_rep' => 150] as $key => $amount) {
            $runs->addAdjustment($bonus, ['employee_id' => $this->employee($key)->id, 'pay_component_id' => $component('bonus'), 'amount' => $amount, 'currency' => 'USD', 'note' => 'مكافأة الربع الثالث'], $this->hrActor());
        }
        app(PayrollCalculator::class)->calculate($bonus->refresh(), $this->hrActor());

        // Loans: one approved by the manager and payroll (now repaying), one waiting for the manager.
        $nextMonth = $t->addMonthNoOverflow()->format('Y-m');
        $loan = app(LoanService::class)->request($this->employee('rehired'), ['amount' => 600, 'currency' => 'USD', 'installments' => 6, 'first_month' => $nextMonth, 'reason' => 'إصلاح المنزل'], (int) $this->employee('rehired')->user_id);
        $this->approve($loan->approval_request_id, 'ops_manager');
        $this->approve($loan->refresh()->approval_request_id, null);
        app(LoanService::class)->request($this->employee('receptionist_probation'), ['amount' => 300, 'currency' => 'USD', 'installments' => 3, 'first_month' => $nextMonth, 'reason' => 'أقساط جامعية'], (int) $this->employee('receptionist_probation')->user_id);
    }

    private function seedBenefits(): void
    {
        $t = $this->today;
        $benefit = fn (array $data) => Benefit::query()->create(['is_active' => true, ...$data]);
        $assign = fn (Benefit $benefit, array $target) => app(BenefitAssignmentService::class)->assign($benefit, ['effective_from' => $t->subYear()->startOfYear()->toDateString(), ...$target], $this->hrActor());

        $cafe = $benefit(['code' => 'cafe-credit', 'name' => ['ar' => 'رصيد المقهى الشهري', 'en' => 'Monthly café credit'], 'kind' => 'in_kind_credit', 'frequency' => 'monthly',
            'amount' => 100, 'unit' => 'points', 'employer_cost' => 8, 'cost_currency' => 'USD', 'fulfillment' => ['category' => 'general']]);
        $assign($cafe, ['org_unit_id' => $this->units['OPS']->id]);
        $assign($cafe, ['employee_id' => $this->employee('barista_suspended')->id, 'excluded' => true]);

        $health = $benefit(['code' => 'health-insurance', 'name' => ['ar' => 'التأمين الصحي', 'en' => 'Health insurance'], 'kind' => 'entitlement', 'frequency' => 'monthly',
            'amount' => 30, 'currency' => 'USD', 'employer_cost' => 30, 'cost_currency' => 'USD']);
        $assign($health, ['org_unit_id' => $this->units['GM']->id]);
        $assign($health, ['job_grade_id' => $this->grades['G4']->id, 'amount' => 45, 'employer_cost' => 45]);

        $welcome = $benefit(['code' => 'welcome-credit', 'name' => ['ar' => 'رصيد ترحيبي', 'en' => 'Welcome credit'], 'kind' => 'in_kind_credit', 'frequency' => 'one_time',
            'amount' => 250, 'unit' => 'points', 'employer_cost' => 20, 'cost_currency' => 'USD']);
        $assign($welcome, ['employee_id' => $this->employee('receptionist_new')->id]);

        // This month's grants: credited to members' wallets, failed for staff without one (HR retries).
        app(BenefitGranter::class)->run($t->startOfMonth());
    }
}
