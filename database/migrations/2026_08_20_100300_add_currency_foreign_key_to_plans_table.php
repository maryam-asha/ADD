<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `pricing_currency` already only ever holds 'USD'/'SYP' (enum
     * validated at the app layer) — no data cleanup needed, just the
     * constraint tying it to the new currencies lookup table.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreign('pricing_currency')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropForeign(['pricing_currency']);
        });
    }
};
