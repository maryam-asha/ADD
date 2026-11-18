<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Stock now sits in warehouses, and every stock screen picks one from
 * `inventory/warehouses` (inventory.warehouses.view). A role that received,
 * issued, moved or counted stock before keeps doing so after deploy: it is
 * given the view once here. Managing warehouses stays an explicit grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        $view = Permission::findOrCreate('inventory.warehouses.view', 'web');

        Role::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', 'like', 'inventory.stock.%'))
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($view));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The grant stays: taking it back could cut off access given since.
    }
};
