<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-slot occupancy counter ERD v2.0 names as load-bearing for the
 * strict no-overbooking ceiling, deferred three times (D.9,
 * reception-operations-scope.md, booking-creation-approval-extension.md)
 * and built here.
 *
 * It is a DERIVED source, not the authority: `bookings` remains the source
 * of truth, and `BookingCreationService` still decides "is this slot free"
 * by counting overlapping bookings under `Space::lockForUpdate()`. This
 * table is written inside that same transaction and lock, and is what the
 * calendar reads. The consequence is the important part — if this table
 * ever drifts, the drift cannot produce an overbooking, only a stale
 * calendar cell, and `booking:reconcile-capacity-slots` rebuilds it from
 * `bookings` at any time. See docs/decisions/booking-availability-and-calendar.md.
 *
 * Rows exist only for slots that actually carry a booking — the table is
 * never pre-filled across the calendar, so it stays proportional to real
 * bookings rather than to spaces × time.
 *
 * `capacity_ceiling` is a write-time snapshot of the space's concurrency
 * ceiling, so a later change to `spaces.capacity` cannot retroactively
 * rewrite what a past slot was sold against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('space_capacity_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces')->cascadeOnDelete();
            $table->dateTime('slot_start');
            $table->dateTime('slot_end');
            $table->unsignedInteger('booked_count')->default(0);
            $table->unsignedInteger('capacity_ceiling');
            $table->timestamps();

            $table->unique(['space_id', 'slot_start'], 'space_capacity_slots_space_slot_unique');
            $table->index(['space_id', 'slot_start', 'slot_end'], 'space_capacity_slots_range_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('space_capacity_slots');
    }
};
