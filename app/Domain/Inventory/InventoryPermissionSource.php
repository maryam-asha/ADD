<?php

namespace App\Domain\Inventory;

use Add\Inventory\Authorization\Abilities;
use App\Services\Permissions\PermissionSource;

/**
 * The inventory package's `can:inventory.*` abilities, as spatie
 * permissions: assignable to any role from the roles screen, all held by
 * admin.
 */
class InventoryPermissionSource implements PermissionSource
{
    public function names(): array
    {
        return Abilities::all();
    }
}
