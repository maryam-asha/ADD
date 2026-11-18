<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public catalog, two-tier Admin/Public controller like Founder
     * (docs/decisions/phase-3-membership-plan-wallet-mechanics.md).
     * `is_subscription=false` is a single-use Hot Desk package that
     * creates a booking directly (Phase 5) and can never create a
     * `memberships` row — build plan §Phase 3 guard. `included_hours` is
     * granted as a `wallet_transactions` credit (category=space_specific)
     * on purchase; `overage_rate` is a catalog attribute only, not applied
     * by any logic yet.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->json('name')->nullable();
            $table->boolean('is_subscription')->default(true);
            $table->decimal('price', 10, 2);
            $table->string('pricing_currency', 3);
            $table->unsignedInteger('duration_days');
            $table->decimal('included_hours', 8, 2)->default(0);
            $table->decimal('overage_rate', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
