<?php

namespace Add\Hr;

use Add\Hr\Approvals\Console\ProcessTimeouts;
use Add\Hr\Approvals\Services\ApprovalTypes;
use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Console\RefreshStatuses;
use Add\Hr\Core\Console\SendAlerts;
use Add\Hr\Core\Events\EmployeeHired;
use Add\Hr\Core\Events\EmployeeTerminated;
use Add\Hr\Core\Listeners\CreateChecklistTasks;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Integrations\LogHrNotifier;
use Add\Hr\Integrations\NullAccountProvisioner;
use Add\Hr\Leave\Console\AccrueLeave;
use Add\Hr\Leave\Console\CloseLeaveYear;
use Add\Hr\Leave\Listeners\CancelLeaveAfterTermination;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Leave\Services\LeaveApprovalHandler;
use Add\Hr\Leave\Services\LeaveService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hr.php', 'hr');

        // Neutral defaults; the host binds its own in its provider, which
        // registers after package providers and so wins.
        $this->app->singletonIf(AccountProvisioner::class, NullAccountProvisioner::class);
        $this->app->singletonIf(HrNotifier::class, LogHrNotifier::class);

        // Each module's request types; a host may register more on the same
        // singleton.
        $this->app->singleton(ApprovalTypes::class, fn () => tap(new ApprovalTypes)
            ->register(LeaveService::APPROVAL_TYPE, LeaveApprovalHandler::class));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'hr');

        $this->app->make(Router::class)->aliasMiddleware('hr.employee', EnsureActiveEmployee::class);

        Event::listen(EmployeeHired::class, CreateChecklistTasks::class);
        Event::listen(EmployeeTerminated::class, CreateChecklistTasks::class);
        Event::listen(EmployeeTerminated::class, CancelLeaveAfterTermination::class);

        // Approval subjects, by name rather than class. Not enforced, so the
        // host's own morph map is unaffected.
        Relation::morphMap(['hr_leave_request' => LeaveRequest::class]);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshStatuses::class,
                SendAlerts::class,
                ProcessTimeouts::class,
                AccrueLeave::class,
                CloseLeaveYear::class,
            ]);
        }

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'hr-config');
    }
}
