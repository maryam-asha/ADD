<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member who scans in well before their coworking booking sits on a
 * walk-in until it starts; this is the booking that walk-in hands over to
 * (docs/decisions/coworking-self-service-sessions.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->foreignId('handover_booking_id')->nullable()->after('kiosk_id')->constrained('bookings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handover_booking_id');
        });
    }
};
