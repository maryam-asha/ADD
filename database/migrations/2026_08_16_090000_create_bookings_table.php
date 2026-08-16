<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Minimum schema for reception operations
     * (docs/superpowers/specs/2026-08-16-reception-operations-design.md) —
     * not the full Phase 5 booking system (no space_capacity_slots,
     * affected_bookings, extension/approval). payment_state defaults to
     * unpaid and payment_source is nullable: payment is a state, never a
     * precondition for creation (2026-08-15 decision session, decisions
     * #1-3) — a booking-creation flow (out of scope this phase) may mark
     * it paid immediately via wallet, or leave it unpaid for reception to
     * settle later.
     */
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('status', 20)->default('confirmed');
            $table->string('payment_state', 20)->default('unpaid');
            $table->string('payment_source', 20)->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();
            $table->string('termination_source', 20)->nullable();
            $table->decimal('amount_owed', 10, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'checked_in_at', 'checked_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
