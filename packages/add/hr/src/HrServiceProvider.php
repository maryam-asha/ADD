<?php

namespace Add\Hr;

use Add\Hr\Approvals\Console\ProcessTimeouts;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Add\Hr\Benefits\Console\GrantBenefits;
use Add\Hr\Benefits\Listeners\SettleBenefitAllowances;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Contracts\EndOfServiceCalculator;
use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Contracts\PayrollAccountingExporter;
use Add\Hr\Contracts\PersonStore;
use Add\Hr\Core\Console\RefreshStatuses;
use Add\Hr\Core\Console\SendAlerts;
use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Core\Listeners\CreateChecklistTasks;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Integrations\ColumnPersonStore;
use Add\Hr\Integrations\ConfigExchangeRateProvider;
use Add\Hr\Integrations\ConfigHrClock;
use Add\Hr\Integrations\CostCenterJournal;
use Add\Hr\Integrations\DaysPerYearEndOfService;
use Add\Hr\Integrations\LogHrNotifier;
use Add\Hr\Integrations\NullAccountProvisioner;
use Add\Hr\Integrations\RecordOnlyBenefitFulfiller;
use Add\Hr\Leave\Console\AccrueLeave;
use Add\Hr\Leave\Console\CloseLeaveYear;
use Add\Hr\Leave\Listeners\CancelLeaveAfterTermination;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Services\LeaveApprovalHandler;
use Add\Hr\Leave\Services\LeaveService;
use Add\Hr\Payroll\Events\PayrollRunApproved;
use Add\Hr\Payroll\Listeners\CancelLoansAfterTermination;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Payroll\Services\LoanApprovalHandler;
use Add\Hr\Payroll\Services\LoanService;
use Add\Hr\Recruitment\Console\PurgeCandidates;
use Add\Hr\Recruitment\Models\Offer;
use Add\Hr\Recruitment\Models\Requisition;
use Add\Hr\Recruitment\Services\OfferApprovalHandler;
use Add\Hr\Recruitment\Services\OfferService;
use Add\Hr\Recruitment\Services\RequisitionApprovalHandler;
use Add\Hr\Recruitment\Services\RequisitionService;
use Add\Hr\Time\Console\ProcessAttendance;
use Add\Hr\Time\Models\AttendanceEntry;
use Add\Hr\Time\Models\OvertimeRequest;
use Add\Hr\Time\Services\AttendanceEntryApprovalHandler;
use Add\Hr\Time\Services\AttendanceEntryService;
use Add\Hr\Time\Services\OvertimeApprovalHandler;
use Add\Hr\Time\Services\OvertimeService;
use Add\Hr\Timesheets\Models\Timesheet;
use Add\Hr\Timesheets\Services\TimesheetApprovalHandler;
use Add\Hr\Timesheets\Services\TimesheetService;
use Add\Hr\Training\Listeners\CancelLearningAfterTermination;
use Add\Hr\Training\Models\LearningRequest;
use Add\Hr\Training\Services\LearningApprovalHandler;
use Add\Hr\Training\Services\LearningRequestService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigDeep(__DIR__.'/../config/hr.php', 'hr');

        // Neutral defaults; the host binds its own in its provider, which
        // registers after package providers and so wins.
        $this->app->singletonIf(AccountProvisioner::class, NullAccountProvisioner::class);
        $this->app->singletonIf(PersonStore::class, ColumnPersonStore::class);
        $this->app->singletonIf(HrNotifier::class, LogHrNotifier::class);
        $this->app->singletonIf(HrClock::class, ConfigHrClock::class);
        $this->app->singletonIf(ExchangeRateProvider::class, ConfigExchangeRateProvider::class);
        $this->app->singletonIf(EndOfServiceCalculator::class, DaysPerYearEndOfService::class);
        $this->app->singletonIf(PayrollAccountingExporter::class, CostCenterJournal::class);
        $this->app->singletonIf(BenefitFulfiller::class, RecordOnlyBenefitFulfiller::class);

        // Each module's request types; a host may register more on the same
        // singleton.
        $this->app->singleton(ApprovalTypes::class, function () {
            $types = new ApprovalTypes;
            $types->register(LeaveService::APPROVAL_TYPE, LeaveApprovalHandler::class);
            $types->register(AttendanceEntryService::APPROVAL_TYPE, AttendanceEntryApprovalHandler::class);
            $types->register(OvertimeService::APPROVAL_TYPE, OvertimeApprovalHandler::class);
            $types->register(TimesheetService::APPROVAL_TYPE, TimesheetApprovalHandler::class);
            $types->register(LoanService::APPROVAL_TYPE, LoanApprovalHandler::class);
            $types->register(RequisitionService::APPROVAL_TYPE, RequisitionApprovalHandler::class);
            $types->register(OfferService::APPROVAL_TYPE, OfferApprovalHandler::class);
            $types->register(LearningRequestService::APPROVAL_TYPE, LearningApprovalHandler::class);

            return $types;
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'hr');

        $this->app->make(Router::class)->aliasMiddleware('hr.employee', EnsureActiveEmployee::class);

        Event::listen(EmployeeHired::class, CreateChecklistTasks::class);
        Event::listen(EmployeeTerminated::class, CreateChecklistTasks::class);
        Event::listen(EmployeeTerminated::class, CancelLeaveAfterTermination::class);
        Event::listen(EmployeeTerminated::class, CancelLoansAfterTermination::class);
        Event::listen(EmployeeTerminated::class, CancelLearningAfterTermination::class);
        Event::listen(PayrollRunApproved::class, SettleBenefitAllowances::class);

        // Approval subjects, by name rather than class. Not enforced, so the
        // host's own morph map is unaffected.
        Relation::morphMap([
            'hr_leave_request' => LeaveRequest::class,
            'hr_attendance_entry' => AttendanceEntry::class,
            'hr_overtime_request' => OvertimeRequest::class,
            'hr_timesheet' => Timesheet::class,
            'hr_loan' => Loan::class,
            'hr_requisition' => Requisition::class,
            'hr_offer' => Offer::class,
            'hr_learning_request' => LearningRequest::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshStatuses::class,
                SendAlerts::class,
                ProcessTimeouts::class,
                AccrueLeave::class,
                CloseLeaveYear::class,
                ProcessAttendance::class,
                GrantBenefits::class,
                PurgeCandidates::class,
            ]);
        }

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'hr-config');
    }

    /**
     * Laravel's mergeConfigFrom() merges only the top level, so a host
     * config/hr.php that sets one option of a group (leave.weekend_days)
     * would drop the group's other defaults. Here the host's values win
     * key by key at every level; a list (weekend_days, mimes) is replaced
     * whole.
     */
    private function mergeConfigDeep(string $path, string $key): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $config->set($key, self::mergeOptions(require $path, $config->get($key, [])));
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $host
     * @return array<array-key, mixed>
     */
    private static function mergeOptions(array $defaults, array $host): array
    {
        foreach ($host as $option => $value) {
            $default = $defaults[$option] ?? null;

            $defaults[$option] = is_array($value) && ! array_is_list($value) && is_array($default) && ! array_is_list($default)
                ? self::mergeOptions($default, $value)
                : $value;
        }

        return $defaults;
    }
}
