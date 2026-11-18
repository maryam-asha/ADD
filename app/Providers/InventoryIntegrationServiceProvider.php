<?php

namespace App\Providers;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Contracts\InventoryNotifier;
use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Inventory;
use App\Domain\Inventory\Adapters\AddCustodianResolver;
use App\Domain\Inventory\Adapters\AddInventoryNotifier;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Domain\Inventory\InventoryPermissionSource;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\ServiceProvider;

/**
 * ADD's side of the inventory package (packages/add/inventory): its
 * places are ADD's physical hierarchy, its custodians ADD's employees,
 * members and companies, and its abilities ADD permissions. The package's
 * routes are mounted in routes/api.php and its schedule in
 * routes/console.php.
 */
class InventoryIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocationResolver::class, AddLocationResolver::class);
        $this->app->singleton(CustodianResolver::class, AddCustodianResolver::class);
        $this->app->singleton(InventoryNotifier::class, AddInventoryNotifier::class);

        $this->app->tag([InventoryPermissionSource::class], 'permissions.sources');
    }

    public function boot(): void
    {
        // Laravel runs in UTC; the business day is the app.timezone setting's,
        // as bookings already read it. A stock operation's date follows it.
        Inventory::resolveTimezoneUsing(fn () => (string) $this->app->make(SettingService::class)->get('app.timezone', 'Asia/Damascus'));
    }
}
