<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan discount's uses are counted per person (product owner,
     * 2026-11-02), and a coworking discount can price a walk-in: each
     * redemption names the person and either the booking or the walk-in it
     * priced. Existing rows take their booking's person.
     */
    public function up(): void
    {
        Schema::table('plan_discount_redemptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('membership_id')->constrained('users')->nullOnDelete();
            $table->foreignId('walkin_session_id')->nullable()->after('booking_id')->constrained('walkin_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('booking_id')->nullable()->change();
        });

        DB::table('plan_discount_redemptions')
            ->whereNull('user_id')
            ->update(['user_id' => DB::raw('(select user_id from bookings where bookings.id = plan_discount_redemptions.booking_id)')]);
    }

    public function down(): void
    {
        DB::table('plan_discount_redemptions')->whereNull('booking_id')->delete();

        Schema::table('plan_discount_redemptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('walkin_session_id');
            $table->dropConstrainedForeignId('user_id');
            $table->unsignedBigInteger('booking_id')->nullable(false)->change();
        });
    }
};
