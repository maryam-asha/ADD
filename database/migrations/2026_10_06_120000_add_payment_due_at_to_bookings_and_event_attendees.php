<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The deadline of a booking or registration held for its prepayment
 * (docs/decisions/prepayment-all-or-nothing.md): set when the hold starts,
 * cleared when it is released, kept when it is cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->index();
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->timestamp('payment_due_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn('payment_due_at');
        });

        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropIndex(['payment_due_at']);
            $table->dropColumn('payment_due_at');
        });
    }
};
