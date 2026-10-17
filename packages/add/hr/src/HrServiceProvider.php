<?php

namespace Add\Hr;

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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'hr');

        $this->app->make(Router::class)->aliasMiddleware('hr.employee', EnsureActiveEmployee::class);

        Event::listen(EmployeeHired::class, CreateChecklistTasks::class);
        Event::listen(EmployeeTerminated::class, CreateChecklistTasks::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RefreshStatuses::class,
                SendAlerts::class,
            ]);
        }

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'hr-config');
    }
}
