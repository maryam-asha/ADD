<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which offers ran when: a copy of an offer or a coupon each time an admin
 * creates, changes or deletes it (CouponHistory). A period is then asked
 * what was running in it, on what terms — after the offer was changed,
 * switched off or deleted alike. `coupon_id` has no foreign key on
 * purpose: a deleted offer keeps its history.
 *
 * Coupons that already exist get one `created` copy of what they are now,
 * dated when they were created: what they were before this table is not
 * known.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupon_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->string('action', 16);
            $table->boolean('is_automatic');
            $table->string('code', 40)->nullable();
            $table->json('name')->nullable();
            $table->string('discount_type', 20);
            $table->unsignedInteger('discount_value');
            $table->unsignedInteger('max_discount_points')->nullable();
            $table->unsignedInteger('min_amount_points')->nullable();
            $table->boolean('combinable_with_offers');
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->boolean('new_members_only');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->json('targets');
            $table->json('user_ids');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['coupon_id', 'recorded_at']);
            $table->index(['is_automatic', 'recorded_at']);
        });

        $now = now();

        DB::table('coupons')->orderBy('id')->each(function (object $coupon) use ($now) {
            DB::table('coupon_revisions')->insert([
                'coupon_id' => $coupon->id,
                'action' => 'created',
                'is_automatic' => $coupon->is_automatic,
                'code' => $coupon->code,
                'name' => $coupon->name,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
                'max_discount_points' => $coupon->max_discount_points,
                'min_amount_points' => $coupon->min_amount_points,
                'combinable_with_offers' => $coupon->combinable_with_offers,
                'usage_limit' => $coupon->usage_limit,
                'per_user_limit' => $coupon->per_user_limit,
                'new_members_only' => $coupon->new_members_only,
                'company_id' => $coupon->company_id,
                'targets' => json_encode(DB::table('coupon_targets')->where('coupon_id', $coupon->id)->orderBy('id')
                    ->get(['purpose', 'plan_id', 'space_id', 'space_type', 'event_id'])->map(fn ($t) => (array) $t)->all()),
                'user_ids' => json_encode(DB::table('coupon_user')->where('coupon_id', $coupon->id)->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all()),
                'starts_at' => $coupon->starts_at,
                'ends_at' => $coupon->ends_at,
                'is_active' => $coupon->is_active,
                'changed_by' => $coupon->created_by,
                'recorded_at' => $coupon->created_at ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_revisions');
    }
};
