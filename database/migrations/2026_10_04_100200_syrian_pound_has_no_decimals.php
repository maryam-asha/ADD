<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Syrian pound has no fractional unit in use, so every SYP amount
     * the system asks for or shows is whole (product owner, 2026-10-04).
     * Every conversion reads `decimal_places`, and rounds a due amount up, so
     * this one value is the whole change: cash asked for at the desk, card
     * charges and displayed prices all become whole pounds.
     */
    public function up(): void
    {
        DB::table('currencies')->where('code', 'SYP')->update(['decimal_places' => 0]);
    }

    public function down(): void
    {
        DB::table('currencies')->where('code', 'SYP')->update(['decimal_places' => 2]);
    }
};
