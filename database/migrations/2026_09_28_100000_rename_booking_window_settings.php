<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The booking-window settings take the names the API now gives them
 * (docs/decisions/booking-availability-and-calendar.md):
 * `booking.min_advance_minutes` → `booking.min_notice_minutes` and
 * `booking.max_advance_days` → `booking.max_days_ahead`. An admin's edited
 * value moves with the row rather than being re-seeded.
 *
 * `booking.max_duration_minutes` is deleted: a booking's length has no
 * ceiling any more, and nothing reads the key.
 *
 * SettingService caches each key forever, but only under its own name, so
 * the renamed keys have never been cached and the old names are never read
 * again. down() renames back; the deleted ceiling comes back as 0 (no limit),
 * which is what it was unless an admin had set it.
 */
return new class extends Migration
{
    private const RENAMES = [
        'booking.min_advance_minutes' => 'booking.min_notice_minutes',
        'booking.max_advance_days' => 'booking.max_days_ahead',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->rename($from, $to);
        }

        DB::table('settings')->where('key', 'booking.max_duration_minutes')->delete();
    }

    public function down(): void
    {
        foreach (self::RENAMES as $from => $to) {
            $this->rename($to, $from);
        }

        if (DB::table('settings')->where('key', 'booking.max_duration_minutes')->doesntExist()) {
            DB::table('settings')->insert([
                'key' => 'booking.max_duration_minutes',
                'scope_type' => 'global',
                'scope_id' => 0,
                'type' => 'int',
                'value' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A row already under the new name (a seeder run ahead of this
     * migration) wins; the old one is then only a leftover.
     */
    private function rename(string $from, string $to): void
    {
        if (DB::table('settings')->where('key', $to)->exists()) {
            DB::table('settings')->where('key', $from)->delete();

            return;
        }

        DB::table('settings')->where('key', $from)->update(['key' => $to, 'updated_at' => now()]);
    }
};
