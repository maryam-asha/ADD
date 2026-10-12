<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paymera takes `amount` as a whole number of the charged currency and
     * charges it as it is, so there is no exponent to record, and the amount
     * is named for what it is. The stored values are already what Paymera
     * charged: nothing to convert.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency_exponent');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('amount_minor', 'charged_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->renameColumn('charged_amount', 'amount_minor');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('currency_exponent')->nullable()->after('currency');
        });
    }
};
