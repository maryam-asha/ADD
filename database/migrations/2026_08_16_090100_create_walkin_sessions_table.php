<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same payment/termination shape as `bookings` (see that migration's
     * docblock), minus a planned window: a walk-in has no start_at/end_at
     * and, per PRD decision #5, no cancellation path — postpaid, settled
     * only after checkout.
     */
    public function up(): void
    {
        Schema::create('walkin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained('spaces');
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('checked_in_at');
            $table->dateTime('checked_out_at')->nullable();
            $table->string('payment_state', 20)->default('unpaid');
            $table->string('payment_source', 20)->nullable();
            $table->string('termination_source', 20)->nullable();
            $table->decimal('amount_owed', 10, 2)->nullable();
            $table->string('currency')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['space_id', 'checked_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walkin_sessions');
    }
};
