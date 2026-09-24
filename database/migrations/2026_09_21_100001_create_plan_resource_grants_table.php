<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A structured sibling of `plan_balances`/`plan_discounts` — a natural-unit
 * quota (not points) a plan grants against a `resource_types` catalog row
 * (docs/decisions/resource-quota-design.md). `resource_type_id` is
 * deliberately `restrictOnDelete()`, NOT cascade: a grant's resource type is
 * its identity, and cascading would let a catalog deletion silently prune
 * grants — and their consumption history — across every plan that grants
 * it. Block the delete instead and make the admin retire the catalog entry
 * (`is_active = false`) rather than remove it, the same posture
 * `plans.pricing_currency -> currencies.code` already takes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_resource_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('resource_type_id')->constrained('resource_types')->restrictOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->json('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_resource_grants');
    }
};
