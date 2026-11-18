<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A booking's human-readable number, "BK-2026-00041" — the series
     * service requests already use ("REQ-…"), with its own prefix so the two
     * never share a counter or read alike (Booking::booted();
     * docs/decisions/booking-outcomes-and-extension.md).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
        });

        // Existing rows in the order they were made, one series per year,
        // and each series' counter left where the backfill stopped so new
        // bookings carry on from there.
        $counters = [];

        DB::table('bookings')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) use (&$counters) {
                $key = 'BK-'.date('Y', strtotime($row->created_at ?? 'now'));
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('bookings')->where('id', $row->id)->update([
                    'reference' => sprintf('%s-%05d', $key, $counters[$key]),
                ]);
            });

        foreach ($counters as $key => $value) {
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('reference', 32)->nullable(false)->change();
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });

        DB::table('reference_sequences')->where('key', 'like', 'BK-%')->delete();
    }
};
