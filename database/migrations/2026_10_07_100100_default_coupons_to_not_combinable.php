<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A coupon no longer stacks on an offer unless it says so: the member gets
 * the better of the two, never both (docs/decisions/pricing-offers-and-coupons.md).
 * Only the default moves — a coupon that exists keeps what it was saved
 * with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('combinable_with_offers')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('combinable_with_offers')->default(true)->change();
        });
    }
};
