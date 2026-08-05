<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The four PRD roles map to three actual accounts — a "visitor" is
     * simply an unauthenticated request, so it never gets a role row.
     */
    public function run(): void
    {
        foreach (['member', 'staff', 'admin'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
