<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A space's sale: an hourly rate and the window it runs in, priced by usage
 * time — the booked seconds inside the window at the sale rate, the rest at
 * the normal rate (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('sale_hourly_rate_points')->nullable()->after('hourly_rate_points');
            $table->dateTime('sale_starts_at')->nullable()->after('sale_hourly_rate_points');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['sale_hourly_rate_points', 'sale_starts_at', 'sale_ends_at']);
        });
    }
};
