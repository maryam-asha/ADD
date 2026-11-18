<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The availability calendar reads a whole month of bookings for a space in
 * one range query. The table's only existing index is
 * `[space_id, checked_in_at, checked_out_at]`, built for reception's
 * "who is in the building right now" question — it cannot serve a
 * `start_at`/`end_at` range scan at all, so the calendar would table-scan
 * `bookings` once per space per view.
 *
 * Column order matters: `space_id` is the equality predicate and must lead,
 * then `start_at`, which carries the range.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['space_id', 'start_at', 'end_at'], 'bookings_space_range_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_space_range_index');
        });
    }
};
