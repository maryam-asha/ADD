<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a booking or a walk-in costs, in points — the unit every payment is
 * settled in (docs/superpowers/specs/2026-09-30-unified-payments-design.md).
 *
 * `amount_owed` stays the money-denominated record (decimal USD). It cannot
 * be turned back into points exactly, so the points are kept beside it:
 * written at booking creation (after any plan discount), and at closure for
 * a walk-in or a booking that closes unpaid, from the same price closure
 * already computes `amount_owed` from.
 *
 * Null on rows that predate this column; the fulfillers fall back to
 * recomputing the price for those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('price_points')->nullable();
        });

        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->unsignedInteger('price_points')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('price_points');
        });

        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropColumn('price_points');
        });
    }
};
