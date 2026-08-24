<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Documentation/parity seeder for reference data, matching how the
     * repo normally seeds lookup tables (RoleSeeder, SettingSeeder). The
     * rows that actually protect tests are inserted directly by
     * 2026_08_20_100000_create_currencies_table's up() — this seeder is
     * secondary and idempotent, safe to re-run.
     */
    public function run(): void
    {
        Currency::updateOrCreate(
            ['code' => 'SYP'],
            [
                'name' => ['ar' => 'ليرة سورية', 'en' => 'Syrian Pound'],
                'symbol' => 'ل.س',
                'decimal_places' => 2,
                'is_base' => false,
                'is_active' => true,
                'order' => 1,
            ]
        );

        Currency::updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => ['ar' => 'دولار أمريكي', 'en' => 'US Dollar'],
                'symbol' => '$',
                'decimal_places' => 2,
                'is_base' => true,
                'is_active' => true,
                'order' => 2,
            ]
        );
    }
}
