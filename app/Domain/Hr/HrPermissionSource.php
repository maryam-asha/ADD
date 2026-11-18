<?php

namespace App\Domain\Hr;

use Add\Hr\Authorization\Abilities;
use App\Services\Permissions\PermissionSource;

/**
 * The HR package's `can:hr.*` abilities, as spatie permissions: assignable
 * to any role from the roles screen, all held by admin.
 */
class HrPermissionSource implements PermissionSource
{
    public function names(): array
    {
        return Abilities::all();
    }
}
