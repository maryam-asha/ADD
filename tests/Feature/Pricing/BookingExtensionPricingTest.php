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
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * An extension is priced at the booking's locked terms — the offer it was
 * priced with, at its copied terms and window, by usage time; else the plan
 * discount it was made with; else the list price — and never with a coupon:
 * a coupon took its points off the booking as it was made
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md, "Locked terms").
 * A room at 100 points an hour, booked 10:00–11:00 on Monday 2026-08-17.
 */
class BookingExtensionPricingTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

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

    /** An offer of 60 an hour on the room over a local window. */
    private function sale(string $from, string $to): Coupon
    {
        return $this->offerOnSpace($this->room, 60, Carbon::parse($from, 'Asia/Damascus')->utc(), Carbon::parse($to, 'Asia/Damascus')->utc());
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

    public function test_an_extension_past_the_offer_window_pays_the_rate_for_the_time_outside(): void
    {
        $this->sale('2026-08-17 00:00', '2026-08-17 11:00');
        $booking = $this->bookAndCheckIn();
        $this->assertSame(60, $booking->price_points);

        $booking = $this->extendAnHour($booking);

        $this->assertSame(160, $booking->price_points);
        $this->assertSame(200, $booking->list_price_points);
        $row = app(CouponLedger::class)->offerFor($booking);
        $this->assertSame(200, $row->base_points);
        $this->assertSame(40, $row->points);
        $this->assertSame(20, $row->percent);
    }

    /** The booking keeps its choice (D7): one priced at the list price takes no new offer. */
    public function test_an_extension_of_a_list_priced_booking_takes_no_new_offer(): void
    {
        $booking = $this->bookAndCheckIn();
        $this->assertSame(100, $booking->price_points);
        $this->sale('2026-08-17 11:00', '2026-08-18 00:00');

        $this->assertSame(100, app(BookingExtensionPlanner::class)->options($booking)->options[0]['points']);

        $booking = $this->extendAnHour($booking);

        $this->assertSame(200, $booking->price_points);
        $this->assertNull(app(CouponLedger::class)->offerFor($booking));
    }

    /** Review Focus 5: the offer's copied terms hold after it is edited or switched off. */
    public function test_an_extension_keeps_the_offer_the_booking_was_priced_with_after_it_is_switched_off(): void
    {
        $offer = Coupon::factory()->automatic()->percentage(50)->create();
        $booking = $this->bookAndCheckIn();
        $this->assertSame(50, $booking->price_points);

        $offer->update(['is_active' => false, 'discount_value' => 10]);
        $booking = $this->extendAnHour($booking);

        $this->assertSame(100, $booking->price_points);
        $row = app(CouponLedger::class)->offerFor($booking);
        $this->assertSame(200, $row->base_points);
        $this->assertSame(100, $row->points);
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
