<?php

namespace Database\Seeders;

use App\Support\ProtectedRoles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * PRD §4 names six roles; only three become spatie role rows. A "guest"
     * is unauthenticated and never gets a row. A "company member" is a
     * `member` with a company_user.door_access_enabled flag, not a
     * separate role (D.8 — no scoped-role system, see
     * docs/decisions/rbac-scoping.md). "Mentor" is deliberately absent:
     * its account structure is an open decision (PRD §7.3 #10) and adding
     * an unusable role would presume an answer to it.
     */
    public function run(): void
    {
        foreach (ProtectedRoles::NAMES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
