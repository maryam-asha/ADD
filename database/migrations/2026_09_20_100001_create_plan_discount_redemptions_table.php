<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The append-only ledger `usage_limit` is enforced against — the same
 * "never store a running counter" convention WalletService already uses
 * for balances (docs/decisions/plan-discounts-design.md). Counted per
 * (plan_discount_id, membership_id), so a fresh Membership on renewal
 * naturally resets the count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_discount_id')->constrained('plan_discounts')->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->unsignedInteger('points_discounted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_discount_redemptions');
    }
};
