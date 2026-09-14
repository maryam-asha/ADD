<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events join the point economy
 * (docs/decisions/add-point-followups.md §1). `price_points` already
 * exists and is what EventRegistrationService actually debits; `price`
 * and `currency` were a second, unlinked pricing path that the resource
 * layer still displayed — so an event could show "$5.00" while charging
 * 200 points. Dropping them leaves one source of truth, per the point
 * system's first invariant: points are stored, currency is derived.
 *
 * No backfill: every row is seeded test data, the same premise the
 * plans/spaces conversion ran on (2026_09_12_100000).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['price', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->default(0.00)->after('is_free');
            $table->string('currency', 3)->default('USD')->after('price');
        });
    }
};
