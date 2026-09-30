<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying for an event registration by any method, and letting the door see
 * whether it was paid (docs/superpowers/specs/2026-09-30-unified-payments-design.md).
 *
 *  - `price_points` is the price at registration, so a later price change
 *    does not change what this member owes. Null for a free event.
 *  - `payment_state` is `paid` / `unpaid` (EventPaymentState); null when
 *    there is nothing to pay. A registration may be approved unpaid — cash
 *    at the desk, or a card still to be paid — but not checked in.
 *  - `payment_method` and the wallet owner are the member's choice, kept
 *    for the moment an approval charges it.
 *  - `checked_in_at` / `checked_in_by` record the door.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->unsignedInteger('price_points')->nullable();
            $table->string('payment_state', 10)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_wallet_owner_type', 20)->nullable();
            $table->unsignedBigInteger('payment_wallet_owner_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checked_in_by');
            $table->dropColumn([
                'price_points',
                'payment_state',
                'payment_method',
                'payment_wallet_owner_type',
                'payment_wallet_owner_id',
                'paid_at',
                'checked_in_at',
            ]);
        });
    }
};
