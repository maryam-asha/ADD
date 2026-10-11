<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            // "REQ-2026-00187" — yearly series, see ServiceRequest::booted().
            $table->string('reference', 32)->nullable()->after('id');
            // When operations closed the request (resolved | rejected).
            $table->timestamp('processed_at')->nullable()->after('submitted_at');
        });

        // Backfill existing rows in submission order, one series per year,
        // and leave each series' counter where the backfill stopped so new
        // requests carry on from there.
        $counters = [];

        DB::table('service_requests')
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get(['id', 'submitted_at', 'status', 'updated_at'])
            ->each(function ($row) use (&$counters) {
                $year = date('Y', strtotime($row->submitted_at ?? 'now'));
                $key = "REQ-{$year}";
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('service_requests')->where('id', $row->id)->update([
                    'reference' => sprintf('%s-%05d', $key, $counters[$key]),
                    // Best available estimate for already-closed requests.
                    'processed_at' => in_array($row->status, ['resolved', 'rejected'], true)
                        ? $row->updated_at
                        : null,
                ]);
            });

        foreach ($counters as $key => $value) {
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
        }

        Schema::table('service_requests', function (Blueprint $table) {
            $table->string('reference', 32)->nullable(false)->change();
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'processed_at']);
        });

        DB::table('reference_sequences')->where('key', 'like', 'REQ-%')->delete();
    }
};
