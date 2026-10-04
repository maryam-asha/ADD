<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plan's sale: a price and the window it runs in, evaluated as of when
 * the payment for it is opened
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('sale_price_points')->nullable()->after('price_points');
            $table->dateTime('sale_starts_at')->nullable()->after('sale_price_points');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['sale_price_points', 'sale_starts_at', 'sale_ends_at']);
        });
    }
};
