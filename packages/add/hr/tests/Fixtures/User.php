<?php

namespace Add\Hr\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host user with nothing HR-specific about it. `abilities` stands in for
 * whatever authorization the host has: TestCase grants exactly these through
 * Gate::before, the way spatie/laravel-permission does in ADD.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = [
        'abilities' => 'array',
    ];
}
