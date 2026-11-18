<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A candidate rate from an external source (sp-today) — an admin may accept
 * it, but it never writes exchange_rates itself and is never authoritative
 * on its own. docs/decisions/exchange-rate-external-suggestion.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rate_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20);
            // SYP per 1 USD, exactly as sp-today quotes it — the opposite
            // direction from exchange_rates.rate_to_base. See the decision
            // doc's "direction problem" section before touching this value.
            $table->decimal('rate_usd_to_syp', 20, 10);
            $table->json('raw_payload');
            $table->dateTime('fetched_at');
            $table->string('status', 20)->default('pending');
            $table->foreignId('accepted_rate_id')->nullable()->constrained('exchange_rates')->nullOnDelete();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rate_suggestions');
    }
};
