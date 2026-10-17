<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Hr;
use Add\Hr\Tests\TestCase;

class RouteMountingTest extends TestCase
{
    public function test_users_table_comes_from_the_configured_model(): void
    {
        $this->assertSame('users', Hr::usersTable());
    }

    public function test_user_model_comes_from_config(): void
    {
        $this->assertSame(\Add\Hr\Tests\Fixtures\User::class, Hr::userModel());
    }
}
