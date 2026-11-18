<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member pressing "end session" in the app; reception confirms it
 * (docs/decisions/coworking-self-service-sessions.md). `requested_at` is
 * what the session is billed to by default, so the member never pays for
 * reception's delay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_end_requests', function (Blueprint $table) {
            $table->id();
            $table->morphs('sessionable');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('requested_at');
            $table->string('status', 20)->default('pending');
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_end_requests');
    }
};
