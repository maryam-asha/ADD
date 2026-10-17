<?php

namespace App\Providers;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use App\Domain\Hr\Adapters\AddAccountProvisioner;
use App\Domain\Hr\Adapters\AddHrClock;
use App\Domain\Hr\Adapters\AddHrNotifier;
use App\Domain\Hr\HrPermissionSource;
use Illuminate\Support\ServiceProvider;

/**
 * ADD's side of the HR package (packages/add/hr): binds the package's
 * contracts to ADD adapters (app/Domain/Hr/Adapters) and publishes its
 * abilities as permissions. The package's routes are mounted in
 * routes/api.php and its schedule in routes/console.php.
 */
class HrIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccountProvisioner::class, AddAccountProvisioner::class);
        $this->app->singleton(HrNotifier::class, AddHrNotifier::class);
        $this->app->singleton(HrClock::class, AddHrClock::class);

        $this->app->tag([HrPermissionSource::class], 'permissions.sources');
    }
}
