<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fixed address for service-request QR codes inside a Co-Space —
     * never a booking unit, and does not reopen the "no seat map" decision
     * (PRD decision #4). `space_id` is not DB-restricted to co_space; that
     * is an application-level rule for whichever form request creates
     * these rows.
     *
     * `qr_point_id` has no foreign-key constraint yet because `qr_points`
     * doesn't exist until Phase 7 — it is added here nullable and wired up
     * with its constraint in that phase's migration, per the backend build
     * plan.
     */
    public function up(): void
    {
        Schema::create('seats_desks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->unsignedBigInteger('qr_point_id')->nullable();
            $table->string('label');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seats_desks');
    }
};
