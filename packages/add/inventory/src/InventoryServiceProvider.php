<?php

namespace Add\Inventory;

use Add\Inventory\Assets\Console\RunDaily;
use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Contracts\InventoryNotifier;
use Add\Inventory\Contracts\LocationResolver;
use Add\Inventory\Integrations\LogInventoryNotifier;
use Add\Inventory\Integrations\NullCustodianResolver;
use Add\Inventory\Integrations\OwnLocationResolver;
use Add\Inventory\Support\Presenter;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigDeep(__DIR__.'/../config/inventory.php', 'inventory');

        // Neutral defaults; the host binds its own in its provider, which
        // registers after package providers and so wins.
        $this->app->singletonIf(LocationResolver::class, OwnLocationResolver::class);
        $this->app->singletonIf(CustodianResolver::class, NullCustodianResolver::class);
        $this->app->singletonIf(InventoryNotifier::class, LogInventoryNotifier::class);

        // Remembers names for one request only.
        $this->app->scoped(Presenter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'inventory');

        if ($this->app->runningInConsole()) {
            $this->commands([RunDaily::class]);
        }

        $this->publishes([__DIR__.'/../config/inventory.php' => config_path('inventory.php')], 'inventory-config');
    }

    /**
     * Laravel's mergeConfigFrom() merges only the top level, so a host
     * config/inventory.php that sets one option of a group (assets.code_prefix)
     * would drop the group's other defaults. Here the host's values win key
     * by key at every level; a list is replaced whole.
     */
    private function mergeConfigDeep(string $path, string $key): void
    {
        if ($this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make('config');
        $config->set($key, self::merge(require $path, $config->get($key, [])));
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $overrides
     * @return array<mixed>
     */
    private static function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            $defaults[$name] = is_array($value) && ! array_is_list($value) && is_array($defaults[$name] ?? null)
                ? self::merge($defaults[$name], $value)
                : $value;
        }

        return $defaults;
    }
}
