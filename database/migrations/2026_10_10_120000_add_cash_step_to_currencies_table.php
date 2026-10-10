<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The smallest amount the reception till takes in this currency. The
     * dashboard rounds a points-first top-up up to a multiple of it and
     * accepts only multiples of it when cash is typed
     * (docs/superpowers/specs/2026-10-10-wallet-top-up-points-entry-design.md).
     * Every row starts at 1: SYP is the new pound, and the till already
     * takes whole pounds only.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->decimal('cash_step', 14, 2)->default(1)->after('decimal_places');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('cash_step');
        });
    }
};
