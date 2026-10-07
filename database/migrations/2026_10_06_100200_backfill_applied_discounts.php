<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fills the ledger for what already happened: each coupon use's percent,
 * and a `plan_discount` row for each booking a plan discount priced — with
 * the plan discount's percentage as it is now, the best record left
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md,
 * "Schema changes" 5). plan_discount_redemptions stays: it drives the plan
 * discount's own usage limit and the legacy refunds.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('applied_discounts')->where('base_points', '>', 0)->orderBy('id')->each(function ($row) {
            DB::table('applied_discounts')->where('id', $row->id)->update(['percent' => intdiv((int) $row->points * 100, (int) $row->base_points)]);
        });

        DB::table('plan_discount_redemptions')
            ->join('plan_discounts', 'plan_discounts.id', '=', 'plan_discount_redemptions.plan_discount_id')
            ->join('bookings', 'bookings.id', '=', 'plan_discount_redemptions.booking_id')
            ->select('plan_discount_redemptions.*', 'plan_discounts.percentage', 'bookings.user_id', 'bookings.list_price_points', 'bookings.price_points')
            ->orderBy('plan_discount_redemptions.id')
            ->each(function ($row) {
                $points = (int) $row->points_discounted;
                $base = (int) ($row->list_price_points ?? ((int) $row->price_points + $points));

                DB::table('applied_discounts')->insertOrIgnore([
                    'user_id' => $row->user_id,
                    'purpose' => 'booking',
                    'booking_id' => $row->booking_id,
                    'kind' => 'plan_discount',
                    'plan_discount_id' => $row->plan_discount_id,
                    'discount_type' => 'percentage',
                    'discount_value' => $row->percentage,
                    'base_points' => $base,
                    'points' => $points,
                    'percent' => $base > 0 ? intdiv($points * 100, $base) : 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            });
    }

    /** The backfilled plan-discount rows go; the columns go with the previous migration. */
    public function down(): void
    {
        DB::table('applied_discounts')->where('kind', 'plan_discount')->delete();
    }
};
