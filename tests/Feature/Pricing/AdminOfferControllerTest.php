<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Offers are coupons with `is_automatic: true` on the same admin endpoints:
 * no code, and `fixed_price` is theirs alone
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class AdminOfferControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'Asia/Damascus')->utc());

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_an_offer_is_created_without_a_code(): void
    {
        $this->postJson('/api/v1/admin/coupons', [
            'is_automatic' => true,
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'name' => ['en' => 'Week', 'ar' => 'أسبوع'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_automatic', true)
            ->assertJsonPath('data.code', null)
            // An offer has no per-member limit unless the admin sets one.
            ->assertJsonPath('data.per_user_limit', null);
    }

    public function test_an_offer_refuses_a_code_and_a_coupon_requires_one(): void
    {
        $this->postJson('/api/v1/admin/coupons', ['is_automatic' => true, 'code' => 'X1', 'discount_type' => 'percentage', 'discount_value' => 20])
            ->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/coupons', ['discount_type' => 'percentage', 'discount_value' => 20])
            ->assertJsonValidationErrors('code');
    }

    public function test_a_fixed_price_is_for_offers_only(): void
    {
        $this->postJson('/api/v1/admin/coupons', ['code' => 'FP', 'discount_type' => 'fixed_price', 'discount_value' => 100, 'targets' => [['purpose' => 'plan_purchase']]])
            ->assertJsonValidationErrors('discount_type');
    }

    public function test_a_fixed_price_offer_needs_targets_of_one_family(): void
    {
        $base = ['is_automatic' => true, 'discount_type' => 'fixed_price', 'discount_value' => 100];

        $this->postJson('/api/v1/admin/coupons', $base)->assertJsonValidationErrors('targets');
        $this->postJson('/api/v1/admin/coupons', $base + ['targets' => [['purpose' => 'booking'], ['purpose' => 'plan_purchase']]])
            ->assertJsonValidationErrors('targets');
        $this->postJson('/api/v1/admin/coupons', $base + ['targets' => [['purpose' => 'booking'], ['purpose' => 'coworking_session']]])
            ->assertCreated();
    }

    public function test_offers_and_coupons_are_listed_apart(): void
    {
        Coupon::factory()->automatic()->create();
        Coupon::factory()->create();

        $this->getJson('/api/v1/admin/coupons?is_automatic=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_automatic', true);
        $this->getJson('/api/v1/admin/coupons?is_automatic=0')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_automatic', false);
    }

    public function test_an_update_cannot_turn_a_coupon_into_an_offer(): void
    {
        $coupon = Coupon::factory()->create();

        $this->patchJson("/api/v1/admin/coupons/{$coupon->id}", ['is_automatic' => true, 'discount_type' => 'percentage', 'discount_value' => 15])->assertOk();

        $this->assertFalse($coupon->fresh()->is_automatic);
        $this->assertNotNull($coupon->fresh()->code);
    }

    public function test_an_offer_is_updated_without_a_code(): void
    {
        $offer = Coupon::factory()->automatic()->create();

        $this->patchJson("/api/v1/admin/coupons/{$offer->id}", ['discount_type' => 'percentage', 'discount_value' => 15])->assertOk();

        $this->assertSame(15, $offer->fresh()->discount_value);
    }

    public function test_a_used_offer_cannot_be_deleted(): void
    {
        $offer = Coupon::factory()->automatic()->create();
        $booking = Booking::factory()->create(['status' => BookingStatus::Confirmed]);
        AppliedDiscount::create([
            'kind' => DiscountKind::Offer,
            'coupon_id' => $offer->id,
            'user_id' => $booking->user_id,
            'purpose' => PricingPurpose::Booking,
            'booking_id' => $booking->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'base_points' => 100,
            'points' => 10,
        ]);

        $this->deleteJson("/api/v1/admin/coupons/{$offer->id}")->assertStatus(409);
    }
}
