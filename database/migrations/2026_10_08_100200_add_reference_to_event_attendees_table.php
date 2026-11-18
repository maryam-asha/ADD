<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A registration's human-readable number, "EVT-2026-00041" — what the
     * member shows at the door and quotes about a paid seat
     * (EventAttendee::booted()).
     */
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
        });

        // Existing rows in the order they were made, one series per year,
        // and each series' counter left where the backfill stopped so new
        // rows carry on from there.
        $counters = [];

        DB::table('event_attendees')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) use (&$counters) {
                $key = 'EVT-'.date('Y', strtotime($row->created_at ?? 'now'));
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('event_attendees')->where('id', $row->id)->update([
                    'reference' => sprintf('%s-%05d', $key, $counters[$key]),
                ]);
            });

        foreach ($counters as $key => $value) {
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
        }

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->string('reference', 32)->nullable(false)->change();
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });

        DB::table('reference_sequences')->where('key', 'like', 'EVT-%')->delete();
    }
};
