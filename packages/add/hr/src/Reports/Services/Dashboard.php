<?php

namespace Add\Hr\Reports\Services;

use Add\Hr\Approvals\Enums\ApprovalStatus;
use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Data\Alert;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Services\AlertService;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Performance\Models\ReviewCycle;
use Add\Hr\Recruitment\Enums\OpeningStatus;
use Add\Hr\Recruitment\Enums\RequisitionStatus;
use Add\Hr\Recruitment\Enums\StageType;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\JobOpening;
use Add\Hr\Recruitment\Models\PipelineStage;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Support\Decimal;
use Add\Hr\Time\Enums\ExceptionStatus;
use Add\Hr\Time\Models\AttendanceException;
use Add\Hr\Timesheets\Enums\TimesheetStatus;
use Add\Hr\Timesheets\Models\Timesheet;
use Add\Hr\Training\Enums\SessionStatus;
use Add\Hr\Training\Models\Certification;
use Add\Hr\Training\Models\TrainingSession;
use Carbon\CarbonImmutable;

/**
 * The HR dashboard: the workforce and its alerts, then one section per
 * enabled module. Payroll appears only to those who may read pay.
 */
class Dashboard
{
    public function __construct(private readonly AlertService $alerts) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $today, bool $withPay): array
    {
        $day = $today->toDateString();
        $employed = HeadcountReport::employedOn($today);
        $monthStart = $today->startOfMonth()->toDateString();
        $alerts = collect($this->alerts->current($today));

        $sections = [
            'as_of' => $day,
            'headcount' => [
                'employed' => (clone $employed)->count(),
                'by_status' => (clone $employed)->get(['status'])->countBy(fn (Employee $employee) => $employee->status->value)->all(),
                'hires_this_month' => Employee::query()->whereBetween('hired_on', [$monthStart, $day])->count(),
                'leavers_this_month' => Employee::query()->whereBetween('terminated_on', [$monthStart, $day])->count(),
            ],
            'alerts' => [
                'total' => $alerts->count(),
                'by_type' => $alerts->countBy(fn (Alert $alert) => $alert->type->value)->all(),
            ],
        ];

        if ($this->enabled('approvals')) {
            $pending = ApprovalRequest::query()->where('status', ApprovalStatus::Pending->value);
            $sections['approvals'] = [
                'pending' => (clone $pending)->count(),
                'by_type' => (clone $pending)->get(['request_type'])->countBy('request_type')->all(),
            ];
        }

        if ($this->enabled('leave')) {
            $sections['leave'] = [
                'pending_requests' => LeaveRequest::query()->where('status', LeaveRequestStatus::Pending->value)->count(),
                'on_leave_today' => LeaveRequest::query()->where('status', LeaveRequestStatus::Approved->value)
                    ->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->distinct()->count('employee_id'),
            ];
        }

        if ($this->enabled('time')) {
            $sections['attendance'] = [
                'open_exceptions' => AttendanceException::query()->where('status', ExceptionStatus::Open->value)->count(),
            ];
        }

        if ($this->enabled('timesheets')) {
            $sections['timesheets'] = [
                'awaiting_approval' => Timesheet::query()->where('status', TimesheetStatus::Submitted->value)->count(),
            ];
        }

        if ($this->enabled('recruitment')) {
            $live = PipelineStage::query()->whereIn('type', [StageType::Open->value, StageType::Offer->value])->orderBy('sort')->pluck('code');
            $byStage = Application::query()->whereIn('stage', $live)->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');
            $sections['recruitment'] = [
                'open_requisitions' => Requisition::query()->where('status', RequisitionStatus::Approved->value)->count(),
                'open_openings' => JobOpening::query()->where('status', OpeningStatus::Open->value)->count(),
                'applications_by_stage' => $live->mapWithKeys(fn (string $code) => [$code => (int) ($byStage[$code] ?? 0)])->all(),
            ];
        }

        if ($this->enabled('performance')) {
            $sections['performance'] = [
                'cycles' => ReviewCycle::query()->whereNotIn('status', [CycleStatus::Draft->value, CycleStatus::Closed->value])->with('reviews')->orderBy('id')->get()
                    ->map(fn (ReviewCycle $cycle) => [
                        'id' => $cycle->id,
                        'name' => $cycle->name,
                        'status' => $cycle->status->value,
                        'reviews_by_status' => $cycle->reviews->countBy(fn ($review) => $review->status->value)->all(),
                    ])->all(),
            ];
        }

        if ($this->enabled('training')) {
            $sections['training'] = [
                'upcoming_sessions' => TrainingSession::query()->where('status', SessionStatus::Scheduled->value)
                    ->whereBetween('starts_on', [$day, $today->addDays(30)->toDateString()])->count(),
                'certifications_expiring' => Certification::query()->whereNotNull('expires_on')
                    ->whereBetween('expires_on', [$day, $today->addDays((int) config('hr.alerts.certification_days', 45))->toDateString()])->count(),
            ];
        }

        if ($withPay && $this->enabled('payroll')) {
            $run = PayrollRun::query()->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')->first();
            $sections['payroll'] = [
                'latest_run' => $run === null ? null : [
                    'id' => $run->id,
                    'year' => $run->year,
                    'month' => $run->month,
                    'status' => $run->status->value,
                    'currency' => $run->currency,
                    'employees' => $run->payslips()->count(),
                    'net_total' => Decimal::trim(Decimal::of($run->payslips()->sum('net'))),
                ],
            ];
        }

        return $sections;
    }

    private function enabled(string $module): bool
    {
        return (bool) config("hr.modules.{$module}", false);
    }
}
