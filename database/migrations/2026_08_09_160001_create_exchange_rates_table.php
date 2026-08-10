<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only by convention: no route ever updates or deletes a row
     * (docs/superpowers/plans/2026-08-09-display-currency.md) — the
     * "current" rate is whichever row has the latest effective_from that
     * has already passed, so history falls out of this table for free.
     */
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->decimal('rate_usd_to_syp', 12, 4);
            $table->dateTime('effective_from')->index();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
