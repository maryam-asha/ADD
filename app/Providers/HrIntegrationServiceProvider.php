<?php

namespace App\Providers;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Contracts\PersonStore;
use App\Domain\Hr\Adapters\AddAccountProvisioner;
use App\Domain\Hr\Adapters\AddExchangeRateProvider;
use App\Domain\Hr\Adapters\AddHrClock;
use App\Domain\Hr\Adapters\AddHrNotifier;
use App\Domain\Hr\Adapters\AddPersonStore;
use App\Domain\Hr\Adapters\AddWalletBenefitFulfiller;
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
        $this->app->singleton(ExchangeRateProvider::class, AddExchangeRateProvider::class);
        $this->app->singleton(BenefitFulfiller::class, AddWalletBenefitFulfiller::class);
        // Scoped: it holds the request's sensitive reads, never a worker's.
        $this->app->scoped(PersonStore::class, AddPersonStore::class);

        $this->app->tag([HrPermissionSource::class], 'permissions.sources');
    }
}
