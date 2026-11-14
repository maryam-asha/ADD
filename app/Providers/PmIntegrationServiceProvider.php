<?php

namespace App\Providers;

use App\Domain\Pm\PmPermissionSource;
use Illuminate\Support\ServiceProvider;

/**
 * ADD's side of the project-management package (packages/add/pm): its
 * abilities become ADD permissions. The package's routes are mounted in
 * routes/api.php.
 */
class PmIntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([PmPermissionSource::class], 'permissions.sources');
    }
}
