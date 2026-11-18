<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `currency` already only ever holds 'USD'/'SYP' (enum validated at
     * the app layer) — no data cleanup needed, just the constraint tying
     * it to the new currencies lookup table. Column is nullable (payment
     * is a state, never a precondition for creation), which the FK allows.
     */
    public function up(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropForeign(['currency']);
        });
    }
};
