<?php

namespace Add\Pm;

use Add\Pm\Contracts\PeopleDirectory;
use Add\Pm\Integrations\UserTablePeopleDirectory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the project-management package into the host: its migrations and
 * its translations (under the `pm` namespace). It mounts no route; the host
 * does that through Pm::adminRoutes() inside its own middleware group.
 */
class PmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Every user, by name; a host with its own notion of "person" binds
        // its directory with singleton and wins.
        $this->app->singletonIf(PeopleDirectory::class, UserTablePeopleDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pm');
    }
}
