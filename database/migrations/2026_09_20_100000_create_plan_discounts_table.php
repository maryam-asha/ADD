<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured sibling of `plan_balances`, next to the purely-textual
 * `plan_features` (docs/decisions/plan-discounts-design.md). `space_id` and
 * `space_type` are mutually exclusive — enforced by
 * PlanDiscount::booted(), mirroring WalletTransaction's own
 * restricted_space_id/category guard — since a specific space already
 * implies its type. `usage_limit` is a redemption counter, not a points
 * budget; it is enforced by counting `plan_discount_redemptions` rows
 * rather than stored here as a running total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->unsignedTinyInteger('percentage');
            $table->foreignId('space_id')->nullable()->constrained('spaces')->nullOnDelete();
            $table->string('space_type')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->json('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_discounts');
    }
};
