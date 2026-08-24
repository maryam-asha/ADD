<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The base currency is USD (docs/decisions/money-model.md, PRD v0.7.1
     * decision #15), not SYP as 2026_08_11_090000_default_preferred_currency_to_syp
     * wrongly set — that migration predates the `currencies` table and is
     * left as historical record rather than rewritten; this layers the
     * correct default on top, the same way the `add_currency_foreign_key_to_*`
     * migrations layered onto the schema instead of editing earlier ones.
     * No existing row is rewritten here — see the decision doc this fix
     * traces back to for why a data rewrite is a separate business call.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->default('USD')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->default('SYP')->change();
        });
    }
};
