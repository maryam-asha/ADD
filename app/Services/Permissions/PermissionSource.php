<?php

namespace App\Services\Permissions;

/**
 * Permission names that come from outside this app's admin controllers — a
 * package's abilities (packages/add/hr). Tag an implementation with
 * `permissions.sources` and PermissionSyncService creates its names, gives
 * them to admin, and stops reporting them as stale.
 */
interface PermissionSource
{
    /**
     * @return list<string>
     */
    public function names(): array;
}
