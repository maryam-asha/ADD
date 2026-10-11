<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a priced record cost before any sale, plan discount or coupon, so
 * its breakdown can be drawn after the rates have moved — display only;
 * refunds keep reading payment rows
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 * A payment carries one when it is itself the priced record: a plan bought.
 */
return new class extends Migration
{
    private const TABLES = [
        'bookings' => 'price_points',
        'walkin_sessions' => 'price_points',
        'event_attendees' => 'price_points',
        'payments' => 'amount_points',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $after) {
            Schema::table($table, function (Blueprint $blueprint) use ($after) {
                $blueprint->unsignedInteger('list_price_points')->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('list_price_points');
            });
        }
    }
};
