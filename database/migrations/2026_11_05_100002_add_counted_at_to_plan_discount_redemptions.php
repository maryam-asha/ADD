<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The cycle a plan discount use counts against: that of what it priced
     * (a booking's start, a walk-in's check-in), not the day it was priced —
     * a booking made in January for February spends February's uses.
     * Existing rows take their booking's start.
     */
    public function up(): void
    {
        Schema::table('plan_discount_redemptions', function (Blueprint $table) {
            $table->dateTime('counted_at')->nullable()->after('walkin_session_id');
        });

        DB::table('plan_discount_redemptions')
            ->whereNull('counted_at')
            ->update(['counted_at' => DB::raw('coalesce((select start_at from bookings where bookings.id = plan_discount_redemptions.booking_id), plan_discount_redemptions.created_at)')]);
    }

    public function down(): void
    {
        Schema::table('plan_discount_redemptions', function (Blueprint $table) {
            $table->dropColumn('counted_at');
        });
    }
};
