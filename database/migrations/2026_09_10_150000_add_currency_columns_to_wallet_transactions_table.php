<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->string('payment_currency', 10)->nullable()->after('payment_method');
            $table->decimal('payment_amount', 14, 2)->nullable()->after('payment_currency');
            $table->decimal('exchange_rate_to_base', 20, 10)->nullable()->after('payment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_currency', 'payment_amount', 'exchange_rate_to_base']);
        });
    }
};
