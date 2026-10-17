<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Hr;
use Add\Hr\Tests\Fixtures\User;
use Add\Hr\Tests\TestCase;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

class RouteMountingTest extends TestCase
{
    private function mountAdminRoutesFresh(): bool
    {
        Route::setRoutes(new RouteCollection);
        Route::prefix('hr')->group(fn () => Hr::adminRoutes());
        Route::getRoutes()->refreshNameLookups();

        return Route::has('hr.org-units.index');
    }

    public function test_an_enabled_module_mounts_its_admin_routes_under_the_hr_name(): void
    {
        config(['hr.modules.core' => true]);

        $this->assertTrue($this->mountAdminRoutesFresh());
    }

    public function test_a_disabled_module_mounts_nothing(): void
    {
        config(['hr.modules.core' => false]);

        $this->assertFalse($this->mountAdminRoutesFresh());
    }

    public function test_users_table_comes_from_the_configured_model(): void
    {
        $this->assertSame('users', Hr::usersTable());
    }

    public function test_user_model_comes_from_config(): void
    {
        $this->assertSame(User::class, Hr::userModel());
    }
}
