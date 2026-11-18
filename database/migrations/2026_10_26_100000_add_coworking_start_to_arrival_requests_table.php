<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kiosk scan no longer starts a coworking session by itself: it files a
 * request reception confirms, and the session starts at that confirmation
 * (docs/decisions/coworking-self-service-sessions.md). The request carries
 * what the scan already settled — the kiosk and its space, the booking to
 * check in or wait for, and what the member was shown and accepted about
 * their plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arrival_requests', function (Blueprint $table) {
            $table->string('purpose', 20)->default('arrival')->after('user_id');
            $table->foreignId('kiosk_id')->nullable()->after('purpose')->constrained('kiosks')->nullOnDelete();
            $table->foreignId('space_id')->nullable()->after('kiosk_id')->constrained('spaces')->nullOnDelete();
            $table->foreignId('handover_booking_id')->nullable()->after('matched_booking_id')->constrained('bookings')->nullOnDelete();
            $table->boolean('accepted_uncovered')->default(false)->after('handover_booking_id');
            $table->json('coverage_snapshot')->nullable()->after('accepted_uncovered');
        });
    }

    public function down(): void
    {
        Schema::table('arrival_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_booking_id');
            $table->dropConstrainedForeignId('space_id');
            $table->dropConstrainedForeignId('kiosk_id');
            $table->dropColumn(['purpose', 'accepted_uncovered', 'coverage_snapshot']);
        });
    }
};
