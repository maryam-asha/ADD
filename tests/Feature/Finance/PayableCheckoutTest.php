<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PaymentStart;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponApplication;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one place an existing booking, session or registration is paid by a
 * chosen method — what POST member/payments did, now called by purpose from
 * each operation's own endpoint
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class PayableCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    private function unpaidBooking(User $member): Booking
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
    }

    public function test_the_wallet_pays_now(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet');

        $this->assertSame(PaymentStart::DONE, $outcome->start->kind);
        $this->assertNotNull($outcome->payment);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
    }

    public function test_cash_sends_the_member_to_reception_and_writes_nothing(): void
    {
        $member = $this->member();
        $booking = $this->unpaidBooking($member);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'cash');

        $this->assertSame(PaymentStart::PAY_AT_RECEPTION, $outcome->start->kind);
        $this->assertNull($outcome->payment);
        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);
    }

    /**
     * The app re-sends, with the id, the code it sent when it made the
     * booking. The booking already carries it: nothing to apply, no refusal.
     */
    public function test_the_code_a_record_already_carries_is_ignored(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $coupon = Coupon::factory()->amountOff(10)->create(['code' => 'TENOFF']);
        app(CouponApplication::class)->apply($booking, $member, $coupon);

        $outcome = app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet', [], 'TENOFF');

        $this->assertSame(PaymentStart::DONE, $outcome->start->kind);
        $this->assertSame(210, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        ));
    }

    public function test_a_different_code_on_a_record_that_has_one_is_refused(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $first = Coupon::factory()->amountOff(10)->create(['code' => 'TENOFF']);
        Coupon::factory()->amountOff(5)->create(['code' => 'OTHER']);
        app(CouponApplication::class)->apply($booking, $member, $first);

        $this->expectException(CouponRejectedException::class);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $booking, $member, 'wallet', [], 'OTHER');
    }
}
