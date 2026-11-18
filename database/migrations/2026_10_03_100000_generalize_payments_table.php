<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One `payments` table for every way of paying and everything paid for
 * (docs/superpowers/specs/2026-09-30-unified-payments-design.md).
 *
 * The table was born as the Paymera ledger, so it assumed a gateway: a
 * terminal, a callback token, a minor-unit amount. A payment by wallet
 * points, or cash at the reception desk, has none of those, and a payment
 * for a booking or a coworking session has no plan or top-up to point at. So:
 *
 *  - `method` says how it is paid. A plain string, not an enum: the set of
 *    methods is extensible through config. Every row that exists today was
 *    paid by card, which is what the column default gives it.
 *  - `payable` (morph) says what it pays for, using the morph-map aliases
 *    (`booking`, `walkin_session`, `event_attendee`).
 *  - `amount_points` is what the payment is worth in the ledger's unit.
 *    Backfilled from `points_to_credit`, which stays as the card path's
 *    own name for the same number.
 *  - `debit_transaction_id` is the spend of a payment made with points;
 *    `refund_transaction_id` and `refunded_at` are its undoing. The existing
 *    `wallet_transaction_id` stays the credit side (money in).
 *  - `collected_via` and the `received_*` columns record how, how much and by
 *    whom reception took money it counted by hand.
 *  - `expires_at` is when an unpaid payment stops being payable.
 *
 * The gateway-only columns become nullable rather than the table being split
 * in two: the lifecycle columns are shared, and splitting would duplicate
 * every one of them. `callback_token` keeps its unique index — nullable
 * columns may repeat NULL under it.
 *
 * `down()` puts NOT NULL back before it drops anything it added. If a payment
 * made without a gateway exists by then, that fails, on purpose: those rows
 * cannot be represented by the old shape, and deleting financial records in
 * a rollback is not a decision for a migration to make.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 20)->default('paymera')->index();

            $table->nullableMorphs('payable');

            $table->unsignedBigInteger('amount_points')->nullable();

            $table->foreignId('debit_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('refund_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();
            $table->dateTime('refunded_at')->nullable();

            // A PaymentMethod value: how the money reached the desk.
            $table->string('collected_via', 20)->nullable();
            $table->decimal('received_amount', 14, 2)->nullable();
            $table->string('received_currency', 10)->nullable();
            $table->foreignId('received_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();

            $table->dateTime('expires_at')->nullable();
        });

        // Only a gateway has these.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('terminal_id', 16)->nullable()->change();
            $table->string('callback_token', 64)->nullable()->change();
            $table->unsignedBigInteger('amount_minor')->nullable()->change();
            $table->string('currency', 3)->nullable()->change();
            $table->unsignedTinyInteger('currency_exponent')->nullable()->change();
            $table->string('lang', 2)->nullable()->change();
        });

        DB::table('payments')->update(['amount_points' => DB::raw('points_to_credit')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('terminal_id', 16)->nullable(false)->change();
            $table->string('callback_token', 64)->nullable(false)->change();
            $table->unsignedBigInteger('amount_minor')->nullable(false)->change();
            $table->string('currency', 3)->nullable(false)->change();
            $table->unsignedTinyInteger('currency_exponent')->nullable(false)->change();
            $table->string('lang', 2)->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['debit_transaction_id']);
            $table->dropForeign(['refund_transaction_id']);
            $table->dropForeign(['received_by']);
            $table->dropIndex(['method']);
            $table->dropMorphs('payable');

            $table->dropColumn([
                'method',
                'amount_points',
                'debit_transaction_id',
                'refund_transaction_id',
                'refunded_at',
                'collected_via',
                'received_amount',
                'received_currency',
                'received_by',
                'received_at',
                'expires_at',
            ]);
        });
    }
};
