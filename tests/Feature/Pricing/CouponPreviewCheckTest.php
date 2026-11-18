<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The first step of "Apply" (coupons/preview): a typed code checked on its
 * own — does it exist, is it live, may this member use it, and, given a
 * purpose and an item, does it apply there. The same rules a purchase runs;
 * nothing is written or spent, and with nothing to price the price fields
 * are null.
 */
class CouponPreviewCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        Sanctum::actingAs($this->member, ['*']);
    }

    /** @param  array<string, mixed>  $body */
    private function check(array $body): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/coupons/preview', $body);
    }

    private function refused(TestResponse $response, string $reason): void
    {
        $response->assertStatus(422)
            ->assertJsonPath('message', __("api.coupon.{$reason}", [], 'en'));
    }

    public function test_a_usable_code_answers_with_its_terms(): void
    {
        Coupon::factory()->percentage(15)->create(['code' => 'SPRING', 'per_user_limit' => 2, 'ends_at' => now()->addDays(3)]);

        $this->check(['code' => 'spring'])
            ->assertOk()
            ->assertJsonMissingPath('message')
            ->assertJsonPath('data.code', 'SPRING')
            ->assertJsonPath('data.discount_type', 'percentage')
            ->assertJsonPath('data.discount_value', 15)
            ->assertJsonPath('data.uses_left', 2)
            // Nothing named to price: the same keys, null.
            ->assertJsonPath('data.applied', null)
            ->assertJsonPath('data.points_discounted', null)
            ->assertJsonPath('data.discount_amount', null);
    }

    /** An unknown and a switched-off code answer alike (D11): probing learns nothing. */
    public function test_an_unknown_or_inactive_code_is_not_found(): void
    {
        Coupon::factory()->create(['code' => 'OFF', 'is_active' => false]);

        $this->refused($this->check(['code' => 'NOPE']), 'not_found');
        $this->refused($this->check(['code' => 'OFF']), 'not_found');
    }

    public function test_dates(): void
    {
        Coupon::factory()->create(['code' => 'LATER', 'starts_at' => now()->addDay()]);
        Coupon::factory()->create(['code' => 'ENDED', 'ends_at' => now()->subMinute()]);

        $this->refused($this->check(['code' => 'LATER']), 'not_started');
        $this->refused($this->check(['code' => 'ENDED']), 'expired');
    }

    public function test_a_code_given_to_someone_else_is_not_for_this_member(): void
    {
        Coupon::factory()->create(['code' => 'THEIRS'])->users()->attach(User::factory()->create());

        $this->refused($this->check(['code' => 'THEIRS']), 'not_eligible');
    }

    public function test_a_code_the_member_used_up(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ONCE', 'per_user_limit' => 1]);
        $booking = Booking::factory()->create(['user_id' => $this->member->id, 'status' => BookingStatus::Confirmed]);
        AppliedDiscount::create([
            'kind' => DiscountKind::Coupon,
            'coupon_id' => $coupon->id,
            'user_id' => $this->member->id,
            'purpose' => PricingPurpose::Booking,
            'booking_id' => $booking->id,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'base_points' => 100,
            'points' => 10,
        ]);

        $this->refused($this->check(['code' => 'ONCE']), 'per_user_limit_reached');
    }

    /** Without an item, what the code is for is not asked; with one, it is. */
    public function test_asked_about_an_item_checks_that_the_code_applies_to_it(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'is_public' => true]);
        $other = Plan::factory()->create(['is_active' => true, 'is_public' => true]);
        Coupon::factory()->create(['code' => 'THATPLAN'])->targets()->create(['purpose' => 'plan_purchase', 'plan_id' => $other->id]);

        $this->check(['code' => 'THATPLAN'])->assertOk();
        $this->check(['code' => 'THATPLAN', 'purpose' => 'plan_purchase', 'plan_id' => $other->id])->assertOk();
        $this->refused($this->check(['code' => 'THATPLAN', 'purpose' => 'plan_purchase', 'plan_id' => $plan->id]), 'not_applicable');
        $this->refused($this->check(['code' => 'THATPLAN', 'purpose' => 'booking']), 'not_applicable');
    }

    public function test_a_code_is_required(): void
    {
        $this->check([])->assertStatus(422)->assertJsonValidationErrors('code');
    }
}
