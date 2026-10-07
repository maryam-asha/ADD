<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * coupon_redemptions becomes the discount ledger, `applied_discounts`: one
 * row per reduction applied to an operation — an offer, a plan discount, a
 * coupon, or a plan's built-in saving (`kind`) — with a copy of where it
 * came from, so a report of what was given can be read back however the
 * offers, coupons and plans change afterwards
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 *
 * A record now takes one row per kind, not one row in all: the single-column
 * uniques become (record, kind). `coupon_id` is null for the kinds that are
 * not a coupon; `points_discounted` is now plain `points`, since a saving's
 * row records what the plan saves rather than what was taken off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('coupon_redemptions', 'applied_discounts');

        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->string('kind', 20)->default('coupon')->after('purpose');
            $table->foreignId('plan_discount_id')->nullable()->after('coupon_id')->constrained('plan_discounts')->nullOnDelete();
            $table->unsignedTinyInteger('percent')->default(0)->after('points_discounted');
            $table->json('terms')->nullable()->after('percent');
            $table->unsignedBigInteger('coupon_id')->nullable()->change();
            $table->string('discount_type', 20)->nullable()->change();
            $table->unsignedInteger('discount_value')->nullable()->change();
        });

        // The (record, kind) uniques first: on MySQL each single-column
        // unique is the only index behind its record's foreign key, and
        // cannot be dropped (error 1553) until another index leads with
        // that column. DDL is not transactional there, so a failure past
        // the rename would leave the migration unrunnable.
        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->unique(['booking_id', 'kind']);
            $table->unique(['walkin_session_id', 'kind']);
            $table->unique(['event_attendee_id', 'kind']);
            $table->unique(['payment_id', 'kind']);
            $table->index(['kind', 'created_at']);
        });

        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->dropUnique('coupon_redemptions_booking_id_unique');
            $table->dropUnique('coupon_redemptions_walkin_session_id_unique');
            $table->dropUnique('coupon_redemptions_event_attendee_id_unique');
            $table->dropUnique('coupon_redemptions_payment_id_unique');
        });

        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->renameColumn('points_discounted', 'points');
        });
    }

    public function down(): void
    {
        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->renameColumn('points', 'points_discounted');
        });

        // The single-column uniques back before the composite ones go, for
        // the same foreign-key reason as up().
        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->unique('booking_id', 'coupon_redemptions_booking_id_unique');
            $table->unique('walkin_session_id', 'coupon_redemptions_walkin_session_id_unique');
            $table->unique('event_attendee_id', 'coupon_redemptions_event_attendee_id_unique');
            $table->unique('payment_id', 'coupon_redemptions_payment_id_unique');
        });

        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->dropIndex(['kind', 'created_at']);
            $table->dropUnique(['booking_id', 'kind']);
            $table->dropUnique(['walkin_session_id', 'kind']);
            $table->dropUnique(['event_attendee_id', 'kind']);
            $table->dropUnique(['payment_id', 'kind']);
        });

        Schema::table('applied_discounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_discount_id');
            $table->dropColumn(['kind', 'percent', 'terms']);
        });

        Schema::rename('applied_discounts', 'coupon_redemptions');
    }
};
