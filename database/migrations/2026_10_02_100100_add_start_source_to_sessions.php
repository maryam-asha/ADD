<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a session started (docs/decisions/coworking-self-service-sessions.md).
 * `coverage_snapshot` is the plan coverage evaluated at start — stored, not
 * recomputed, so what the member agreed to never drifts afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->string('started_via', 20)->default('reception')->after('checked_in_at');
            $table->foreignId('kiosk_id')->nullable()->after('started_via')->constrained('kiosks')->nullOnDelete();
            $table->boolean('accepted_uncovered')->default(false)->after('kiosk_id');
            $table->json('coverage_snapshot')->nullable()->after('accepted_uncovered');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->string('checked_in_via', 20)->nullable()->after('checked_in_at');
            $table->foreignId('kiosk_id')->nullable()->after('checked_in_via')->constrained('kiosks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropColumn('checked_in_via');
        });

        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropColumn(['started_via', 'accepted_uncovered', 'coverage_snapshot']);
        });
    }
};
