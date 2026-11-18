<?php

namespace App\Providers;

use Add\Pm\Contracts\PeopleDirectory;
use App\Domain\Pm\AddPeopleDirectory;
use App\Domain\Pm\PmPermissionSource;
use Illuminate\Support\ServiceProvider;

/**
 * ADD's side of the project-management package (packages/add/pm): its
 * abilities become ADD permissions, and the people a project can hold are
 * staff with an active employee record (AddPeopleDirectory). The package's routes are mounted in
 * routes/api.php.
 */
class PmIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PmPermissionSource::class], 'permissions.sources');
        $this->app->singleton(PeopleDirectory::class, AddPeopleDirectory::class);
    }
}
