<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Card payments through Paymera eGate (docs/paymera-integration-plan.md).
 *
 * There is no `orders` table in this app — money enters as a wallet point
 * credit, so a payment points at the wallet it will fund and, for a plan
 * purchase, at the plan it will activate. Both are nullable-by-purpose
 * rather than split across two tables: the gateway lifecycle is identical
 * and splitting it would duplicate every status column.
 *
 * Column choices worth stating:
 *
 *  - `status` is a string + PHP backed enum cast, never a MySQL ENUM
 *    (build plan §A.4, guarded by NoNewMysqlEnumColumnsTest).
 *  - `amount_minor` is a bigInteger: it is Paymera's own integer minor-unit
 *    amount, echoed back by get-payment-status and compared byte-for-byte
 *    against what we sent. Storing it as the gateway states it is what
 *    makes that comparison meaningful. No FLOAT/DOUBLE anywhere
 *    (decision #15, guarded by MoneyIsDecimalOnlyTest).
 *  - `points_to_credit` is fixed at creation, not recomputed on return.
 *    The point rate is an admin-editable setting; if it moved while the
 *    member was on the hosted card page, they must still receive what the
 *    app quoted them before they typed their card number.
 *  - `callback_token` is the URL-facing identifier, not `payment_id`. It is
 *    random, so the public callback/trigger routes cannot be enumerated.
 *  - `raw_response` keeps the last gateway payload verbatim for disputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // Paymera's id for the payment. Nullable only for the instant
            // between INSERT and a successful create-payment call.
            $table->string('payment_id')->nullable()->unique();

            // What the public callback/trigger URLs carry instead of an id.
            $table->string('callback_token', 64)->unique();

            $table->string('purpose');
            $table->string('status')->default('pending')->index();

            // Who pays, and which wallet the points land in.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            // Set only when purpose = plan_purchase.
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            // The membership this payment produced, once it has produced one.
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();

            // The ledger row that credited the points, once credited. This
            // is what makes "already credited" answerable without inferring
            // it from status alone.
            $table->foreignId('wallet_transaction_id')->nullable()
                ->constrained('wallet_transactions')->nullOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->unsignedTinyInteger('currency_exponent');
            $table->unsignedBigInteger('points_to_credit');

            // Verbatim at creation time; every later status check compares
            // the gateway's terminalId against this as a string.
            $table->string('terminal_id', 16);
            $table->string('lang', 2);

            // The bank's reference number for the settled transaction.
            $table->string('rrn')->nullable();
            $table->timestamp('gateway_created_at')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('check_attempts')->default(0);

            $table->json('raw_response')->nullable();

            // Why a payment ended up needing a human. Free text, because
            // the reasons are diagnostic rather than branched on.
            $table->text('review_reason')->nullable();

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The reconciler's query: pending payments, oldest first.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
