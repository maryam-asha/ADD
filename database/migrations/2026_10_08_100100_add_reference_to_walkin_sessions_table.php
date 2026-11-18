<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A walk-in's human-readable number, "WS-2026-00041" — so a coworking
     * session has one whichever way it started, as a booking's "BK-…" does
     * (WalkinSession::booted()).
     */
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
        });

        // Existing rows in the order they were made, one series per year,
        // and each series' counter left where the backfill stopped so new
        // rows carry on from there.
        $counters = [];

        DB::table('walkin_sessions')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) use (&$counters) {
                $key = 'WS-'.date('Y', strtotime($row->created_at ?? 'now'));
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('walkin_sessions')->where('id', $row->id)->update([
                    'reference' => sprintf('%s-%05d', $key, $counters[$key]),
                ]);
            });

        foreach ($counters as $key => $value) {
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
        }

        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable(false)->change();
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });

        DB::table('reference_sequences')->where('key', 'like', 'WS-%')->delete();
    }
};
