<?php

namespace App\Domain\Pm;

use Add\Pm\Authorization\Abilities;
use App\Services\Permissions\PermissionSource;

/**
 * The project-management package's `can:pm.*` abilities, as spatie
 * permissions: assignable to any role from the roles screen, all held by
 * admin.
 */
class PmPermissionSource implements PermissionSource
{
    public function names(): array
    {
        return Abilities::all();
    }
}
