<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger becomes point-denominated
 * (docs/superpowers/specs/2026-09-12-add-point-c-wallet-design.md).
 * `amount` stays signed — positive credit, negative debit — so it is a
 * plain integer(), never unsignedInteger().
 *
 * No backfill: every row is disposable test data as of 2026-09-12.
 *
 * payment_currency / payment_amount / exchange_rate_to_base survive and
 * are joined by point_rate_usd. All four are audit-only (Invariant 3):
 * they record what was handed over and at what rates, and no balance
 * computation may read them — enforced by
 * tests/Guards/WalletBalanceIgnoresAuditColumnsTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->integer('amount')->default(0)->after('wallet_id');
            $table->decimal('point_rate_usd', 8, 4)->nullable()->after('exchange_rate_to_base');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['amount', 'point_rate_usd']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->default(0)->after('wallet_id');
        });
    }
};
