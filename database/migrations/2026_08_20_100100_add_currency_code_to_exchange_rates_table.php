<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `exchange_rates` becomes multi-currency: every row now names the
     * currency it converts (`currency_code`), and the column that used to
     * assume USD->SYP is renamed to `rate_to_base` — "units of the base
     * currency (SYP) per 1 unit of currency_code". The base currency
     * itself never gets a row here; its rate to itself is implicitly 1.
     *
     * The only pair ever stored to date was USD->SYP, so every existing
     * row backfills to currency_code = 'USD' before the column is made
     * non-nullable and FK'd to currencies.code.
     */
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('id');
        });

        DB::table('exchange_rates')->update(['currency_code' => 'USD']);

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable(false)->change();
            $table->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();

            $table->renameColumn('rate_usd_to_syp', 'rate_to_base');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->renameColumn('rate_to_base', 'rate_usd_to_syp');
        });

        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropForeign(['currency_code']);
            $table->dropColumn('currency_code');
        });
    }
};
