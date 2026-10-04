<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a coupon applies to. No rows: everything a coupon can be spent on.
 * A row names a purpose and, optionally, one item of it. The items restrict
 * on delete — cascading a target away would silently widen the coupon to
 * the whole purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->string('purpose', 30);
            $table->foreignId('plan_id')->nullable()->constrained('plans')->restrictOnDelete();
            $table->foreignId('space_id')->nullable()->constrained('spaces')->restrictOnDelete();
            $table->string('space_type', 30)->nullable();
            $table->foreignId('event_id')->nullable()->constrained('events')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_targets');
    }
};
