<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(SettingSeeder::class);
        $this->call(CurrencySeeder::class);
        $this->call(AnnouncementSeeder::class);
        $this->call(ContactLinkSeeder::class);
        $this->call(AdminUserSeeder::class);
        $this->call(SpaceSeeder::class);
        $this->call(PlanModuleSeeder::class);
        $this->call(PlanSeeder::class);
        $this->call(ServiceAxisSeeder::class);
    }
}
