<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A wallet row's human-readable number, "TRX-2026-00041" — stored, as
     * bookings' "BK-…" and service requests' "REQ-…" are, instead of being
     * pieced together from the date and id each time the receipt is shown
     * (WalletTransaction::booted()).
     */
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->after('id');
        });

        // Existing rows in the order they were made, one series per year,
        // and each series' counter left where the backfill stopped so new
        // rows carry on from there.
        $counters = [];

        DB::table('wallet_transactions')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'created_at'])
            ->each(function ($row) use (&$counters) {
                $key = 'TRX-'.date('Y', strtotime($row->created_at ?? 'now'));
                $counters[$key] = ($counters[$key] ?? 0) + 1;

                DB::table('wallet_transactions')->where('id', $row->id)->update([
                    'reference' => sprintf('%s-%05d', $key, $counters[$key]),
                ]);
            });

        foreach ($counters as $key => $value) {
            DB::table('reference_sequences')->updateOrInsert(['key' => $key], ['last_value' => $value]);
        }

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable(false)->change();
            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });

        DB::table('reference_sequences')->where('key', 'like', 'TRX-%')->delete();
    }
};
