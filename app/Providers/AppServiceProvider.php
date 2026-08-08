<?php

namespace App\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\CompanyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user, string $ability) => $user->hasRole('admin') ? true : null);

        // Models live under App\Domain\<Domain>\Policies, not App\Policies, so
        // Laravel's convention-based policy discovery misses these — register
        // explicitly. This is the only Policy in the app (D.8).
        Gate::policy(Company::class, CompanyPolicy::class);

        // Models live under App\Domain\<Domain>\Models, not App\Models, so Laravel's
        // default guess (Database\Factories\Domain\<Domain>\Models\XFactory) misses.
        // Factories stay flat in database/factories/ and are matched by class name.
        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }
}
