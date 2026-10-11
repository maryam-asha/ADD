<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A booking's `checked_in_at` is never before its `start_at`: an early
 * arrival is let in, but BookingCheckInService records the session as
 * beginning when the booking does. The CHECK constraint holds the database
 * to the same, whatever writes the row.
 *
 * Rows that already break it are moved to their start first — the time the
 * service would have recorded — or adding the constraint would fail.
 *
 * MySQL/MariaDB only: SQLite (the test database) cannot add a constraint to
 * an existing table, so there the service's own tests carry the rule.
 */
return new class extends Migration
{
    private const NAME = 'bookings_checked_in_not_before_start';

    public function up(): void
    {
        if (! $this->supported()) {
            return;
        }

        DB::table('bookings')
            ->whereNotNull('checked_in_at')
            ->whereColumn('checked_in_at', '<', 'start_at')
            ->update(['checked_in_at' => DB::raw('start_at')]);

        DB::statement('ALTER TABLE bookings ADD CONSTRAINT '.self::NAME.' CHECK (checked_in_at IS NULL OR checked_in_at >= start_at)');
    }

    public function down(): void
    {
        if (! $this->supported()) {
            return;
        }

        DB::statement('ALTER TABLE bookings DROP CONSTRAINT '.self::NAME);
    }

    private function supported(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
};
