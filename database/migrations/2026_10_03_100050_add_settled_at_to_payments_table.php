<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `settled_at` is when PaymentService finished a payment: the money credited,
 * what was due spent, and the purchase delivered.
 *
 * Neither link on the row can say that on its own. `status` cannot — a card
 * payment is Accepted by the gateway before its points are spent. And
 * `debit_transaction_id` cannot — a purchase that costs nothing (a free
 * plan, a category debit of zero) writes no debit row, so a second settle
 * would deliver it again.
 *
 * Backfill: an accepted card top-up was settled when its credit landed, and
 * an accepted card plan purchase when its membership was created. Rows that
 * are neither are left null, which is what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dateTime('settled_at')->nullable();
        });

        DB::table('payments')
            ->where('status', 'accepted')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('purpose', 'wallet_top_up')->whereNotNull('wallet_transaction_id'))
                    ->orWhere(fn ($q) => $q->where('purpose', 'plan_purchase')->whereNotNull('membership_id'));
            })
            ->update(['settled_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('settled_at');
        });
    }
};
