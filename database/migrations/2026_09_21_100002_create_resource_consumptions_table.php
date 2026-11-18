<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only ledger a `plan_resource_grants.quantity` quota is
 * enforced against — the same "never store a running counter" convention
 * `plan_discount_redemptions` already uses (docs/decisions/resource-quota-design.md).
 * There is no running counter on the grant row itself: "remaining" is
 * always computed at read time by summing this table, scoped per
 * `(plan_resource_grant_id, membership_id)`, so a plan renewal — a new
 * `Membership` row on the same plan — resets consumption back to zero for
 * free, with nothing to reset by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_resource_grant_id')->constrained('plan_resource_grants')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2);
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_consumptions');
    }
};
