<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Models\Coupon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * coupon_redemptions becomes applied_discounts: one row per reduction on a
 * record, of four kinds, at most one of each per record
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class AppliedDiscountsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function rename(): object
    {
        return require database_path('migrations/2026_10_06_100100_turn_coupon_redemptions_into_applied_discounts.php');
    }

    private function backfill(): object
    {
        return require database_path('migrations/2026_10_06_100200_backfill_applied_discounts.php');
    }

    private function rollBack(): void
    {
        $this->backfill()->down();
        $this->rename()->down();
    }

    private function migrate(): void
    {
        $this->rename()->up();
        $this->backfill()->up();
    }

    public function test_existing_coupon_uses_become_coupon_rows_with_a_percent(): void
    {
        $this->rollBack();
        $booking = Booking::factory()->create();
        DB::table('coupon_redemptions')->insert([
            'coupon_id' => Coupon::factory()->create()->id,
            'user_id' => $booking->user_id,
            'purpose' => 'booking',
            'booking_id' => $booking->id,
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'base_points' => 200,
            'points_discounted' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migrate();

        $row = DB::table('applied_discounts')->where('booking_id', $booking->id)->first();
        $this->assertSame('coupon', $row->kind);
        $this->assertSame(30, (int) $row->points);
        $this->assertSame(15, (int) $row->percent);
    }

    public function test_each_plan_discount_redemption_gets_a_plan_discount_row(): void
    {
        $this->rollBack();
        $member = User::factory()->create();
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create(['percentage' => 25]);
        $membership = Membership::factory()->create(['plan_id' => $plan->id, 'owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $booking = Booking::factory()->create(['user_id' => $member->id, 'list_price_points' => 200, 'price_points' => 150]);
        DB::table('plan_discount_redemptions')->insert([
            'plan_discount_id' => $discount->id,
            'membership_id' => $membership->id,
            'booking_id' => $booking->id,
            'points_discounted' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migrate();

        $row = DB::table('applied_discounts')->where('booking_id', $booking->id)->first();
        $this->assertSame('plan_discount', $row->kind);
        $this->assertSame($discount->id, (int) $row->plan_discount_id);
        $this->assertNull($row->coupon_id);
        $this->assertSame('percentage', $row->discount_type);
        $this->assertSame(25, (int) $row->discount_value);
        $this->assertSame(200, (int) $row->base_points);
        $this->assertSame(50, (int) $row->points);
        $this->assertSame(25, (int) $row->percent);
        $this->assertSame($member->id, (int) $row->user_id);
    }

    public function test_a_record_takes_one_row_per_kind(): void
    {
        $booking = Booking::factory()->create();
        $coupon = Coupon::factory()->create();
        $row = fn (string $kind) => [
            'coupon_id' => $coupon->id,
            'user_id' => $booking->user_id,
            'purpose' => 'booking',
            'booking_id' => $booking->id,
            'kind' => $kind,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'base_points' => 100,
            'points' => 10,
            'percent' => 10,
        ];

        DB::table('applied_discounts')->insert($row('coupon'));
        DB::table('applied_discounts')->insert($row('offer'));
        $this->assertSame(2, DB::table('applied_discounts')->where('booking_id', $booking->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('applied_discounts')->insert($row('coupon'));
    }
}
