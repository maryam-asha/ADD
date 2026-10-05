<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per price a coupon was locked into — the ledger its limits are
 * counted against, never a stored counter. Exactly one of the four record
 * columns is set (explicit foreign keys, as plan_discount_redemptions has).
 *
 * The coupon's terms are copied in, so editing a coupon never changes a
 * price it already set: a check-out or a plan settled later reads them from
 * here. Whether a row still counts follows from its record's status
 * (CouponRedemption::scopeLive), so there is nothing to void.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('purpose', 30);
            $table->foreignId('booking_id')->nullable()->unique()->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('walkin_session_id')->nullable()->unique()->constrained('walkin_sessions')->cascadeOnDelete();
            $table->foreignId('event_attendee_id')->nullable()->unique()->constrained('event_attendees')->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->cascadeOnDelete();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('max_discount_points')->nullable();
            $table->unsignedInteger('base_points');
            $table->unsignedInteger('points_discounted');
            $table->timestamps();

            $table->index(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
    }
};
