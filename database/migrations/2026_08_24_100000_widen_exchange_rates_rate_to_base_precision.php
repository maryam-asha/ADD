<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * decimal(12,4) couldn't represent a currency this much weaker than the
     * base without collapsing it: 1 SYP = 1/14700 USD ≈ 0.0000680272, which
     * needs 10 decimal places — 4 rounds it to 0.0001, a ~47% error.
     * decimal(20,10) gives enough headroom for any currency at least this
     * far from the base without hitting the same wall again.
     */
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->decimal('rate_to_base', 20, 10)->change();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->decimal('rate_to_base', 12, 4)->change();
        });
    }
};
