<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/decisions/wallet-points-categorization.md — built to the full
     * categorized/restricted shape from this first migration, per that
     * doc's own instruction not to ship a minimal top-up-only version
     * first. `amount` is signed: positive is a credit/grant, negative is
     * a debit. `restricted_space_id` is only ever set alongside
     * `category = space_specific` — enforced by a model-level validator
     * (WalletTransaction::booted()), not a DB constraint, since it spans
     * two independently-nullable columns. `source` is documentation/
     * reporting only, never read by spend-resolution logic. No stored
     * balance — "available balance" is always computed by summing
     * non-expired, eligible rows at read time
     * (docs/decisions/phase-3-membership-plan-wallet-mechanics.md).
     */
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets');
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->string('category', 20)->default('general');
            $table->foreignId('restricted_space_id')->nullable()->constrained('spaces');
            $table->string('source', 30)->default('top_up');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
