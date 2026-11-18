<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon: a code a member types, its discount, and who and what it is
 * for (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * `company_id` restricts on delete: nulling it would quietly widen a
 * company's coupon to every member. `source` lets a gift code (phase 2) and
 * a referral code (phase 3) share the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->string('source', 20)->default('admin')->index();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('max_discount_points')->nullable();
            $table->unsignedInteger('min_amount_points')->nullable();
            $table->boolean('combinable_with_offers')->default(true);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->boolean('new_members_only')->default(false);
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
