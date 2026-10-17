<?php

namespace Add\Hr\Tests;

use Add\Hr\Hr;
use Add\Hr\HrServiceProvider;
use Add\Hr\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn ($user, string $ability) => in_array($ability, $user->abilities ?? [], true) ? true : null);
    }

    protected function getPackageProviders($app): array
    {
        return [HrServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('hr.user_model', User::class);
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Mounted the way a host does it (ADD: routes/api.php): admin and
     * self-service behind authentication, signed routes without it.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'auth'])->prefix('api/hr')->group(function () use ($router) {
            $router->middleware('hr.employee')->prefix('me')->group(fn () => Hr::selfServiceRoutes());
            Hr::adminRoutes();
        });

        $router->middleware('api')->prefix('api/hr')->group(fn () => Hr::signedRoutes());
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /**
     * @param  list<string>  $abilities
     */
    protected function actingAsWith(array $abilities = []): User
    {
        $user = User::query()->create([
            'name' => 'Tester '.uniqid(),
            'email' => uniqid().'@example.test',
            'abilities' => $abilities,
        ]);

        $this->actingAs($user);

        return $user;
    }
}
