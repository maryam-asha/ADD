<?php

namespace App\Providers;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Contracts\InventoryNotifier;
use Add\Inventory\Contracts\LocationResolver;
use App\Domain\Inventory\Adapters\AddCustodianResolver;
use App\Domain\Inventory\Adapters\AddInventoryNotifier;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Domain\Inventory\InventoryPermissionSource;
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
}
