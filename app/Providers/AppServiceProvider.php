<?php

namespace App\Providers;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\CompanyPolicy;
use App\Listeners\EnsureAuthenticatedUserIsActive;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Events\TokenAuthenticated;

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

        // Applies to every guard, every route, with no per-route/group
        // registration — see EnsureAuthenticatedUserIsActive's own docblock
        // for why this is an event listener and not a middleware.
        Event::listen(TokenAuthenticated::class, EnsureAuthenticatedUserIsActive::class);
        Event::listen(Authenticated::class, EnsureAuthenticatedUserIsActive::class);

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
