<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An "evening package" — a plan whose hours are only usable between, say,
 * 20:00 and 00:00 — plus private plans negotiated for one company's staff
 * (docs/decisions/plan-access-windows.md).
 *
 * The window is two `H:i` strings, not a native TIME column and not a Carbon
 * cast, matching the `business_hours` precedent (and `Setting`'s before it):
 * `->time(` appears nowhere in this app. Both null means no window, which is
 * every plan that existed before this migration.
 *
 * `is_public` defaults to true so no existing plan silently disappears from
 * the catalog the moment this runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('access_window_start', 5)->nullable()->after('duration_days');
            $table->string('access_window_end', 5)->nullable()->after('access_window_start');
            $table->boolean('is_public')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['access_window_start', 'access_window_end', 'is_public']);
        });
    }
};
