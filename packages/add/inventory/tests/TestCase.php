<?php

namespace Add\Inventory\Tests;

use Add\Inventory\Inventory;
use Add\Inventory\InventoryServiceProvider;
use Add\Inventory\Tests\Fixtures\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A column missing from a model's $fillable fails loudly instead of
        // being dropped without a trace.
        Model::preventSilentlyDiscardingAttributes();

        Gate::before(fn ($user, string $ability) => in_array($ability, $user->abilities ?? [], true) ? true : null);
    }

    protected function getPackageProviders($app): array
    {
        return [InventoryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('inventory.user_model', User::class);
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Mounted the way a host does it (ADD: routes/api.php), behind its own
     * authentication.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'auth'])->prefix('api/inventory')->group(fn () => Inventory::adminRoutes());
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
