<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight "I'm here" signal from a member's app after scanning the
 * static kiosk QR (docs/decisions/kiosk-display.md). Never creates a
 * booking or session by itself — reception confirms manually via the
 * existing check-in/walk-in endpoints, recorded here only as
 * confirmed_by_user_id/confirmed_space_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('requested_at');
            $table->foreignId('matched_booking_id')->nullable()->constrained('bookings');
            $table->string('status', 20)->default('pending');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
            $table->foreignId('confirmed_space_id')->nullable()->constrained('spaces');
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_requests');
    }
};
