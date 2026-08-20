<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Bootstraps the first admin account. Public registration is disabled
     * on purpose (Fortify::registration() is off) — every operations/admin
     * account originates from an existing admin, and this seeder exists
     * only to create that very first one for local development.
     *
     * Run manually: php artisan db:seed --class=AdminUserSeeder
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['phone' => '+963900000000'],
            [
                'name' => 'ADD Admin',
                'email' => 'admin@add.local',
                'password' => 'password',
                'preferred_language' => 'ar',
                'preferred_currency' => 'SYP',
                'status' => 'active',
            ]
        );

        $user->assignRole('admin');
    }
}
