<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Before sales and coupons, the only thing ever taken off a price was a
 * booking's plan discount: a booking's list price is its price plus that,
 * and everything else's list price is its price.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->whereNotNull('price_points')
            ->whereNull('list_price_points')
            ->update([
                'list_price_points' => DB::raw('price_points + COALESCE((SELECT SUM(points_discounted) FROM plan_discount_redemptions WHERE plan_discount_redemptions.booking_id = bookings.id), 0)'),
            ]);

        foreach (['walkin_sessions', 'event_attendees'] as $table) {
            DB::table($table)
                ->whereNotNull('price_points')
                ->whereNull('list_price_points')
                ->update(['list_price_points' => DB::raw('price_points')]);
        }

        DB::table('payments')
            ->where('purpose', 'plan_purchase')
            ->whereNotNull('amount_points')
            ->whereNull('list_price_points')
            ->update(['list_price_points' => DB::raw('amount_points')]);
    }

    public function down(): void
    {
        // The column itself is dropped by the migration that added it.
    }
};
