<?php

namespace Database\Seeders\HrDemo;

use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Leave\Models\CoverageRule;
use Add\Hr\Leave\Models\Holiday;
use Add\Hr\Leave\Models\LeavePolicy;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Models\LeaveType;
use Add\Hr\Leave\Services\LeaveAccrual;
use Add\Hr\Leave\Services\LeaveLedger;
use Add\Hr\Leave\Services\LeaveService;
use Add\Hr\Time\Enums\ExceptionResolution;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Time\Models\AttendancePolicy;
use Add\Hr\Time\Services\AttendanceCards;
use Add\Hr\Time\Services\AttendanceEntryService;
use Add\Hr\Time\Services\ExceptionReview;
use Add\Hr\Time\Services\OvertimeService;
use Add\Hr\Time\Services\PunchRecorder;
use Add\Hr\Time\Services\RosterService;
use Add\Hr\Time\Services\ScheduleResolver;
use Add\Hr\Time\Services\TimePeriodService;
use Carbon\CarbonImmutable;

/**
 * Leave (types, policies, holidays, minimum staffing, balances and requests
 * in every state) and time (an attendance policy, the reception roster,
 * staff cards, last month's attendance reviewed and locked, this month's
 * punches with their irregularities, missed-time entries and overtime).
 */
trait SeedsHrLeaveAndTime
{
    /** Reception's shifts: the two receptionists swap every other day. */
    private const ROSTER = ['reception_lead' => 'morning', 'receptionist_new' => 'evening', 'receptionist_probation' => 'morning', 'leaving' => 'evening'];

    /** @var array<string, LeaveType> */
    private array $leaveTypes = [];

    private function seedLeave(): void
    {
        $t = $this->today;

        $type = fn (string $code, string $ar, string $en, array $flags) => $this->leaveTypes[$code] = LeaveType::query()->create([
            'code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'is_active' => true, ...$flags,
        ]);
        $type('annual', 'إجازة سنوية', 'Annual leave', ['is_paid' => true, 'tracks_balance' => true, 'allows_half_day' => true]);
        $type('sick', 'إجازة مرضية', 'Sick leave', ['is_paid' => true, 'tracks_balance' => true, 'hide_reason' => true]);
        $type('unpaid', 'إجازة بلا أجر', 'Unpaid leave', ['is_paid' => false, 'tracks_balance' => false]);
        $type('permission', 'إذن ساعي', 'Hourly permission', ['unit' => 'hour', 'is_paid' => true, 'tracks_balance' => true]);

        $policy = fn (string $type, array $rules) => LeavePolicy::query()->create([
            'leave_type_id' => $this->leaveTypes[$type]->id, 'effective_from' => $t->subYears(5)->startOfYear()->toDateString(), 'max_negative' => 0, ...$rules,
        ]);
        $policy('annual', ['accrual_method' => 'annual_grant', 'annual_entitlement' => 21, 'carry_over_max' => 5, 'carry_over_expiry_months' => 3, 'max_negative' => 2,
            'service_tiers' => [['min_years' => 5, 'entitlement' => 25]]]);
        $policy('sick', ['accrual_method' => 'annual_grant', 'annual_entitlement' => 14]);
        $policy('unpaid', ['accrual_method' => 'none', 'annual_entitlement' => 0]);
        $policy('permission', ['accrual_method' => 'annual_grant', 'annual_entitlement' => 16]);

        foreach ([['01-01', 'رأس السنة الميلادية', "New Year's Day"], ['04-17', 'عيد الجلاء', 'Evacuation Day'], ['05-01', 'عيد العمال', 'Labour Day'], ['10-06', 'ذكرى حرب تشرين', 'Tishreen War Day'], ['12-25', 'عيد الميلاد', 'Christmas']] as [$day, $ar, $en]) {
            Holiday::query()->create(['name' => ['ar' => $ar, 'en' => $en], 'starts_on' => "{$t->year}-{$day}", 'ends_on' => "{$t->year}-{$day}", 'recurs_annually' => true]);
        }
        $eid = $this->workday($t->addMonths(2));
        Holiday::query()->create(['name' => ['ar' => 'عطلة عيد (تجريبية)', 'en' => 'Eid holiday (demo)'], 'starts_on' => $eid->toDateString(), 'ends_on' => $eid->addDays(2)->toDateString(), 'recurs_annually' => false]);

        CoverageRule::query()->create(['org_unit_id' => $this->units['RCP']->id, 'min_present' => 1, 'is_active' => true]);

        $this->seedRoster();
        app(LeaveAccrual::class)->run($t);
        app(LeaveLedger::class)->record($this->employee('ceo'), $this->leaveTypes['annual'], LedgerKind::Adjustment, 2, $t, $t->year,
            ['notes' => 'تعويض عن العمل في عطلة', 'created_by' => $this->hrActor()]);

        $ask = function (string $key, string $type, CarbonImmutable $from, ?CarbonImmutable $to = null, array $extra = []): LeaveRequest {
            return app(LeaveService::class)->submit($this->employee($key), [
                'leave_type_id' => $this->leaveTypes[$type]->id,
                'starts_on' => $from->toDateString(),
                'ends_on' => ($to ?? $from)->toDateString(),
                'reason' => $extra['reason'] ?? null,
                ...$extra,
            ], null, (int) $this->employee($key)->user_id);
        };

        // Approved and taken; on leave today; approved for later.
        $past = $this->workday($t->subMonth());
        $this->approve($ask('ops_agent', 'annual', $past, $this->workday($past->addDay()), ['reason' => 'سفر عائلي'])->approval_request_id, 'ops_manager');
        $this->approve($ask('sales_rep', 'annual', $this->workday($t), $this->workday($this->workday($t)->addDays(2)), ['reason' => 'زفاف أخي'])->approval_request_id, 'sales_manager');
        $later = $this->workday($t->addMonth());
        $this->approve($ask('accountant', 'annual', $later, $this->workday($later->addDays(2)))->approval_request_id, 'ceo');
        $sick = $this->workday($t->subDays(9));
        $this->approve($ask('receptionist_new', 'sick', $sick, null, ['reason' => 'زكام حاد'])->approval_request_id, 'reception_lead');
        $unpaid = $this->workday($t->subDays(20));
        $this->approve($ask('rehired', 'unpaid', $unpaid, null, ['reason' => 'ظرف شخصي'])->approval_request_id, 'ops_manager');

        // Waiting for the manager; refused; withdrawn by the employee.
        $ask('hr_officer', 'annual', $this->workday($t->addWeek()), $this->workday($t->addWeek()->addDay()), ['reason' => 'إجازة قصيرة']);
        $ask('intern', 'permission', $this->workday($t->addDays(3)), null, ['hours' => 2, 'reason' => 'امتحان جامعي']);
        $refused = $ask('barista_fixed', 'annual', $this->workday($t->addDays(10)), $this->workday($t->addDays(12)));
        $this->approve($refused->approval_request_id, 'ops_manager', reject: true, comment: 'ضغط عمل في المقهى ذلك الأسبوع');
        $withdrawn = $ask('developer', 'annual', $this->workday($t->addDays(30)), $this->workday($t->addDays(33)));
        app(LeaveService::class)->cancel($withdrawn, (int) $this->employee('developer')->user_id);
    }

    private function rosterShift(string $key, CarbonImmutable $day): string
    {
        $shift = self::ROSTER[$key];
        $swap = $day->dayOfYear % 2 === 1 && in_array($key, ['receptionist_new', 'receptionist_probation'], true);

        return $swap ? ($shift === 'morning' ? 'evening' : 'morning') : $shift;
    }

    /** From the start of last month to two weeks ahead, so leave and attendance find reception's working days. */
    private function seedRoster(): void
    {
        $entries = [];
        $until = $this->today->addDays(14);

        for ($day = $this->today->subMonthNoOverflow()->startOfMonth(); $day->lte($until); $day = $day->addDay()) {
            if (! $this->workday($day)->equalTo($day)) {
                continue;
            }

            foreach (array_keys(self::ROSTER) as $key) {
                if ($this->employee($key)->isEmployedOn($day)) {
                    $entries[] = ['employee_id' => $this->employee($key)->id, 'work_date' => $day->toDateString(), 'shift_template_id' => $this->shifts[$this->rosterShift($key, $day)]->id];
                }
            }
        }

        app(RosterService::class)->save($entries, $this->hrActor());
    }

    private function seedTime(): void
    {
        $t = $this->today;

        AttendancePolicy::query()->create([
            'effective_from' => $t->subYear()->startOfYear()->toDateString(), 'grace_minutes' => 10, 'early_leave_grace_minutes' => 10,
            'min_overtime_minutes' => 30, 'overtime_requires_pre_approval' => false, 'entry_tolerance_minutes' => 15,
        ]);

        $cards = app(AttendanceCards::class);
        foreach (['reception_lead', 'receptionist_new', 'receptionist_probation', 'leaving', 'barista_fixed', 'rehired', 'ops_agent', 'sales_rep'] as $key) {
            $cards->issue($this->employee($key), $this->hrActor());
        }

        // Last month: card scans began in its final week (before that the
        // days are dismissed: attendance was on paper), reviewed and locked
        // for payroll.
        $last = $t->subMonthNoOverflow();
        $lastDays = [];
        for ($day = $last->endOfMonth()->startOfDay(); count($lastDays) < 5; $day = $day->subDay()) {
            if ($this->workday($day)->equalTo($day)) {
                $lastDays[] = $day;
            }
        }
        foreach (array_reverse($lastDays) as $i => $day) {
            $this->punchDay($day, $i, irregular: false);
        }
        $periods = app(TimePeriodService::class);
        $periods->rebuild($last->startOfMonth(), $last->endOfMonth());
        $review = app(ExceptionReview::class);
        AttendanceException::query()->whereNull('resolution')->get()->each(
            fn (AttendanceException $exception) => $review->resolve($exception, ExceptionResolution::Dismissed, 'قبل تفعيل بطاقات الدوام: الحضور ورقي', $this->hrActor()),
        );
        $periods->lock($last->year, $last->month, $this->hrActor());

        // This month so far: the irregularities HR is working through.
        $days = array_values(array_filter($this->recentWorkdays(8), fn (CarbonImmutable $d) => $d->month === $t->month));
        foreach ($days as $i => $day) {
            $this->punchDay($day, $i, irregular: true);
        }

        if ($days === []) {
            return;
        }

        $periods->rebuild($days[0], end($days));

        // The day with no punch: excused after the fact.
        $absence = AttendanceException::query()->where('employee_id', $this->employee('accountant')->id)->whereNull('resolution')->first();
        if ($absence !== null) {
            $review->resolve($absence, ExceptionResolution::Excused, 'مهمة خارجية في المصرف', $this->hrActor());
        }

        // Missed times sent in: one waiting for the manager, one approved.
        if (isset($days[3])) {
            app(AttendanceEntryService::class)->submit($this->employee('sales_rep'), ['work_date' => $days[3]->toDateString(), 'check_out' => '16:05', 'note' => 'نسيت تسجيل الخروج'], (int) $this->employee('sales_rep')->user_id);
        }
        if (isset($days[2])) {
            $entry = app(AttendanceEntryService::class)->submit($this->employee('hr_officer'), ['work_date' => $days[2]->toDateString(), 'check_in' => '08:00', 'check_out' => '16:00', 'note' => 'نسيت البطاقة في المنزل'], (int) $this->employee('hr_officer')->user_id);
            if ($entry->approval_request_id) {
                $this->approve($entry->approval_request_id, 'ceo');
            }

            // Overtime: two hours approved, one more hour still waiting.
            $overtime = app(OvertimeService::class)->submit($this->employee('ops_agent'), ['work_date' => $days[2]->toDateString(), 'minutes' => 120, 'reason' => 'تجهيز قاعة فعالية'], (int) $this->employee('ops_agent')->user_id);
            $this->approve($overtime->approval_request_id, 'ops_manager');
        }
        if (isset($days[5])) {
            app(OvertimeService::class)->submit($this->employee('ops_agent'), ['work_date' => $days[5]->toDateString(), 'minutes' => 60, 'reason' => 'جرد المخزن'], (int) $this->employee('ops_agent')->user_id);
        }
    }

    /**
     * Everyone's card scans for one working day. With `irregular`, the cases
     * HR has to look at: an absence, a forgotten card, a missing check-out,
     * lateness, leaving early and overtime.
     */
    private function punchDay(CarbonImmutable $day, int $i, bool $irregular): void
    {
        $tz = app(ScheduleResolver::class)->timezone();
        $recorder = app(PunchRecorder::class);
        $punch = fn (string $key, string $time) => $recorder->record(
            $this->employee($key), CarbonImmutable::parse($day->toDateString().' '.$time, $tz), PunchSource::CardScan,
            ['device_type' => 'kiosk', 'device_id' => 'demo-reception'],
        );
        $present = fn (string $key) => $this->employee($key)->isEmployedOn($day)
            && ! ($key === 'barista_suspended' && $day->gte($this->today->subDays(5)))
            && ! LeaveRequest::query()->where('employee_id', $this->employee($key)->id)->where('status', 'approved')
                ->where('starts_on', '<=', $day->toDateString())->where('ends_on', '>=', $day->toDateString())->exists();

        foreach (['ceo', 'ops_manager', 'hr_officer', 'accountant', 'sales_manager', 'sales_rep', 'ops_agent', 'barista_fixed', 'barista_suspended', 'rehired', 'resigned'] as $key) {
            if (! $present($key)) {
                continue;
            }

            $in = sprintf('07:%02d', 50 + ($i + strlen($key)) % 10);
            $out = sprintf('16:%02d', ($i * 3 + strlen($key)) % 12);

            match (true) {
                $irregular && $key === 'accountant' && $i === 1 => null,                       // absent: no punch at all
                $irregular && $key === 'hr_officer' && $i === 2 => null,                       // forgot the card all day
                $irregular && $key === 'sales_rep' && $i === 3 => $punch($key, $in),           // never punched out
                $irregular && $key === 'barista_fixed' && in_array($i, [0, 4], true) => [$punch($key, '08:27'), $punch($key, $out)],
                $irregular && $key === 'ops_agent' && $i === 2 => [$punch($key, $in), $punch($key, '18:00')],
                $irregular && $key === 'ops_agent' && $i === 5 => [$punch($key, $in), $punch($key, '17:05')],
                $irregular && $key === 'rehired' && $i === 3 => [$punch($key, $in), $punch($key, '15:15')],
                default => [$punch($key, $in), $punch($key, $out)],
            };
        }

        foreach (array_keys(self::ROSTER) as $key) {
            if ($present($key)) {
                $evening = $this->rosterShift($key, $day) === 'evening';
                $punch($key, $evening ? '13:55' : '07:56');
                $punch($key, $evening ? '22:03' : '16:02');
            }
        }

        foreach (['developer', 'intern'] as $key) {
            if ($present($key) && ($i % 2 === 0 || $key === 'developer')) {
                $punch($key, '09:1'.($i % 10));
                $punch($key, '17:3'.($i % 10));
            }
        }
    }
}
