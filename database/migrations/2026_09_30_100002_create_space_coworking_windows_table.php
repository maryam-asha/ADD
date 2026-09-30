<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A room lent to co-working for a stretch of time when the co-working
     * space is overloaded: while it lasts, its `seats` join the branch's
     * seat pool and the room refuses room bookings
     * (docs/decisions/coworking-seat-pool.md). Scheduled by staff, and only
     * over a stretch the room has no bookings in.
     *
     * Instants are UTC, like every other booking column.
     */
    public function up(): void
    {
        Schema::create('space_coworking_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('seats');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['space_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_coworking_windows');
    }
};
