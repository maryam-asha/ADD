<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `booking.duration_options_minutes` — the ladder of lengths the
 * duration-first pickers offered — has no reader any more: the member app
 * and the desk both pick a booking as a run of the space's cells
 * (docs/decisions/booking-availability-and-calendar.md). The row is deleted
 * so the settings page stops offering a value that does nothing.
 *
 * down() puts it back with the seeded ladder, not any value an admin had
 * edited — that value is gone with the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->where('key', 'booking.duration_options_minutes')->delete();
    }

    public function down(): void
    {
        if (DB::table('settings')->where('key', 'booking.duration_options_minutes')->exists()) {
            return;
        }

        DB::table('settings')->insert([
            'key' => 'booking.duration_options_minutes',
            'scope_type' => 'global',
            'scope_id' => 0,
            'type' => 'json',
            'value' => '[60,90,120,180]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
