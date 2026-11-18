<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1's per-item sale — one price and window on a space or a plan — is
 * replaced by automatic offers on the coupon engine, which have history,
 * reach, an audience and limits
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 * Phase 1 never reached production, so nothing migrates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn(['sale_hourly_rate_points', 'sale_starts_at', 'sale_ends_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['sale_price_points', 'sale_starts_at', 'sale_ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->unsignedInteger('sale_hourly_rate_points')->nullable()->after('hourly_rate_points');
            $table->dateTime('sale_starts_at')->nullable()->after('sale_hourly_rate_points');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('sale_price_points')->nullable()->after('price_points');
            $table->dateTime('sale_starts_at')->nullable()->after('sale_price_points');
            $table->dateTime('sale_ends_at')->nullable()->after('sale_starts_at');
        });
    }
};
