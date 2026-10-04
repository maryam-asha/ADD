<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coupons and sales on a booking as it is made
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 * A room at 100 points an hour; 2026-08-17 is a Monday.
 */
class BookingCouponCreationTest extends TestCase
{
    use RefreshDatabase;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $this->room->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
    }

    private function member(int $points = 1000): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);

        return $member;
    }

    private function book(User $member, int $hour, ?string $code = null, ?string $method = 'wallet'): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return app(BookingCreationService::class)->create(
            $this->room,
            $member,
            $start,
            $start->copy()->addHour(),
            paymentMethod: $method,
            couponCode: $code,
        );
    }

    private function balance(User $member): int
    {
        return app(WalletService::class)->generalBalance(app(WalletService::class)->walletFor(OwnerType::User, $member->id));
    }

    public function test_a_coupon_takes_its_discount_off_the_charge(): void
    {
        $coupon = Coupon::factory()->percentage(20)->create(['code' => 'SPRING20']);
        $member = $this->member();

        $booking = $this->book($member, 10, 'spring20');

        $this->assertSame(80, $booking->price_points);
        $this->assertSame(100, $booking->list_price_points);
        $this->assertSame('8.00', (string) $booking->amount_owed);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(920, $this->balance($member));

        $redemption = app(CouponLedger::class)->forRedeemable($booking);
        $this->assertSame($coupon->id, $redemption->coupon_id);
        $this->assertSame(20, $redemption->points_discounted);
        $this->assertSame(100, $redemption->base_points);
    }

    /** Payment is a state, not a precondition: an unpaid booking holds its use. */
    public function test_an_unpaid_booking_holds_its_coupon_use_until_cancelled(): void
    {
        $coupon = Coupon::factory()->percentage(20)->create(['code' => 'DESK']);
        $member = $this->member();

        $booking = $this->book($member, 10, 'DESK', 'cash');

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(80, $booking->price_points);
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));

        app(BookingCancellationService::class)->cancel($booking);

        $this->assertSame(0, app(CouponLedger::class)->liveUsage($coupon));
    }

    public function test_the_first_n_members_only(): void
    {
        Coupon::factory()->percentage(50)->create(['code' => 'FIRST1', 'usage_limit' => 1]);

        $this->book($this->member(), 10, 'FIRST1');

        try {
            $this->book($this->member(), 12, 'FIRST1');
            $this->fail('The second use should be refused.');
        } catch (CouponRejectedException $e) {
            $this->assertSame(CouponRejection::UsageLimitReached, $e->rejection);
        }

        $this->assertSame(1, Booking::count());
    }

    public function test_an_unknown_code_makes_no_booking(): void
    {
        $this->expectException(CouponRejectedException::class);

        try {
            $this->book($this->member(), 10, 'NOPE');
        } finally {
            $this->assertSame(0, Booking::count());
        }
    }

    public function test_a_sale_prices_the_booking_by_usage_time(): void
    {
        $this->room->update([
            'sale_hourly_rate_points' => 60,
            'sale_starts_at' => Carbon::parse('2026-08-17 00:00:00', 'Asia/Damascus')->utc(),
            'sale_ends_at' => Carbon::parse('2026-08-18 00:00:00', 'Asia/Damascus')->utc(),
        ]);
        $member = $this->member();

        $booking = $this->book($member, 10);

        $this->assertSame(60, $booking->price_points);
        $this->assertSame(100, $booking->list_price_points);
        $this->assertSame(940, $this->balance($member));
    }

    /** A plan discount's use is spent only when it, not the sale, priced the booking. */
    public function test_the_plan_discount_is_redeemed_only_when_it_was_chosen(): void
    {
        $member = $this->member();
        $plan = Plan::factory()->create();
        $plan->discounts()->create(['percentage' => 30, 'space_id' => $this->room->id]);
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addDays(30),
        ]);

        $planPriced = $this->book($member, 10);
        $this->assertSame(70, $planPriced->price_points);
        $this->assertSame(30, PlanDiscountRedemption::where('booking_id', $planPriced->id)->value('points_discounted'));

        $this->room->update([
            'sale_hourly_rate_points' => 50,
            'sale_starts_at' => Carbon::parse('2026-08-17 00:00:00', 'Asia/Damascus')->utc(),
            'sale_ends_at' => Carbon::parse('2026-08-18 00:00:00', 'Asia/Damascus')->utc(),
        ]);

        $salePriced = $this->book($member, 12);
        $this->assertSame(50, $salePriced->price_points);
        $this->assertFalse(PlanDiscountRedemption::where('booking_id', $salePriced->id)->exists());
    }

    public function test_the_app_sends_the_code_and_hears_what_it_did(): void
    {
        Coupon::factory()->amountOff(30)->create(['code' => 'THIRTY']);
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $this->room->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'wallet',
            'coupon_code' => 'thirty',
        ])
            ->assertCreated()
            ->assertJsonPath('coupon.code', 'THIRTY')
            ->assertJsonPath('coupon.applied', true)
            ->assertJsonPath('coupon.points_discounted', 30);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $this->room->id,
            'start_at' => '2026-08-17T12:00:00+03:00',
            'end_at' => '2026-08-17T13:00:00+03:00',
            'payment_method' => 'wallet',
            'coupon_code' => 'NOPE',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.not_found', [], 'en'));
    }

    /** A booking a coupon makes free has no card payment to open. */
    public function test_a_free_booking_opens_no_card_payment(): void
    {
        Coupon::factory()->percentage(100)->create(['code' => 'FREE']);
        $member = $this->member(0);
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $this->room->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
            'coupon_code' => 'FREE',
        ])
            ->assertCreated()
            ->assertJsonPath('payment', null)
            ->assertJsonPath('coupon.points_discounted', 100);

        $this->assertSame(0, Booking::firstOrFail()->price_points);
    }
}
