<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionPlanner;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An extension is priced at the booking's locked terms — the sale by usage
 * time, or the plan discount it was made with — and never with a coupon:
 * a coupon took its points off the booking as it was made
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md, D7).
 * A room at 100 points an hour, booked 10:00–11:00 on Monday 2026-08-17.
 */
class BookingExtensionPricingTest extends TestCase
{
    use RefreshDatabase;

    private Space $room;

    private User $member;

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

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);
    }

    private function sale(string $from, string $to): void
    {
        $this->room->update([
            'sale_hourly_rate_points' => 60,
            'sale_starts_at' => Carbon::parse($from, 'Asia/Damascus')->utc(),
            'sale_ends_at' => Carbon::parse($to, 'Asia/Damascus')->utc(),
        ]);
    }

    private function bookAndCheckIn(?string $code = null): Booking
    {
        $start = Carbon::parse('2026-08-17 10:00', 'Asia/Damascus');
        $booking = app(BookingCreationService::class)->create($this->room->fresh(), $this->member, $start, $start->copy()->addHour(), paymentMethod: 'wallet', couponCode: $code);

        $booking->forceFill(['checked_in_at' => $start->copy()->utc()])->save();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));

        return $booking->refresh();
    }

    private function extendAnHour(Booking $booking): Booking
    {
        app(BookingExtensionService::class)->extend($booking, 60);

        return $booking->refresh();
    }

    public function test_an_extension_past_the_sale_is_at_the_normal_rate(): void
    {
        $this->sale('2026-08-17 00:00', '2026-08-17 11:00');
        $booking = $this->bookAndCheckIn();
        $this->assertSame(60, $booking->price_points);

        $booking = $this->extendAnHour($booking);

        $this->assertSame(160, $booking->price_points);
        $this->assertSame(200, $booking->list_price_points);
    }

    public function test_an_extension_into_a_sale_is_at_the_sale_rate(): void
    {
        $this->sale('2026-08-17 11:00', '2026-08-18 00:00');
        $booking = $this->bookAndCheckIn();
        $this->assertSame(100, $booking->price_points);

        $this->assertSame(60, app(BookingExtensionPlanner::class)->options($booking)->options[0]['points']);

        $booking = $this->extendAnHour($booking);

        $this->assertSame(160, $booking->price_points);
        $this->assertSame(200, $booking->list_price_points);
    }

    public function test_the_coupon_is_not_taken_off_the_extension(): void
    {
        $coupon = Coupon::factory()->percentage(20)->create(['code' => 'TWENTY']);
        $booking = $this->bookAndCheckIn('TWENTY');
        $this->assertSame(80, $booking->price_points);

        $booking = $this->extendAnHour($booking);

        $this->assertSame(180, $booking->price_points);
        $this->assertSame(200, $booking->list_price_points);
        $this->assertSame(20, app(CouponLedger::class)->couponFor($booking)->points);
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));
    }
}
