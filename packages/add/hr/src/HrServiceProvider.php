<?php

namespace Add\Hr;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Integrations\NullAccountProvisioner;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hr.php', 'hr');

        // Neutral defaults; the host binds its own in its provider, which
        // registers after package providers and so wins.
        $this->app->singletonIf(AccountProvisioner::class, NullAccountProvisioner::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'hr');

        $this->app->make(Router::class)->aliasMiddleware('hr.employee', EnsureActiveEmployee::class);

        $this->publishes([__DIR__.'/../config/hr.php' => config_path('hr.php')], 'hr-config');
    }
}
