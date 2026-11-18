<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * An extended booking tells its member until when, and how the added time
 * was paid: taken from their wallet, to pay at the desk, still owed, or
 * nothing at all when it cost nothing.
 */
class BookingExtendedNoticeTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus')->utc());
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
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    /** 10:00-11:00 at `$rate` points an hour, unpaid, checked in. */
    private function booking(User $member, int $rate = 100, ?string $method = null): Booking
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => $rate, 'slot_granularity_minutes' => 60]);
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
            'checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'amount_owed' => (string) ($rate / 10),
            'price_points' => $rate,
            'payment_state' => $rate === 0 ? PaymentState::Paid : PaymentState::Unpaid,
            'payment_source' => null,
            'chosen_payment_method' => $method,
        ]);
    }

    /** By an hour, to 12:00. */
    private function extend(Booking $booking, ?string $method = null): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')->utc());

        app(BookingExtensionService::class)->extend($booking, 60, $method);
    }

    /** @return array{title: string, body: string} */
    private function notice(User $member, string $locale): array
    {
        return $this->noticeText($this->assertNoticed($member, 'booking.extended'), $locale);
    }

    public function test_a_wallet_paid_booking_says_the_extension_was_taken_from_the_wallet(): void
    {
        $member = $this->member(1000);
        $booking = $this->booking($member, method: 'wallet');
        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking]);

        $this->extend($booking, 'wallet');

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame('تم تمديد الحجز', $this->notice($member, 'ar')['title']);
        $this->assertStringEndsWith('حتى 12:00. اقتُطع 100 نقطة من محفظتك.', $this->notice($member, 'ar')['body']);
        $this->assertStringEndsWith('extended until 12:00. 100 points were taken from your wallet.', $this->notice($member, 'en')['body']);
    }

    public function test_a_wallet_too_short_for_the_extension_says_the_difference_is_owed(): void
    {
        $member = $this->member(100);
        $booking = $this->booking($member, method: 'wallet');
        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking]);

        $this->extend($booking);

        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);
        $this->assertStringEndsWith('until 12:00. 100 points are due; pay them in the app or at reception.', $this->notice($member, 'en')['body']);
    }

    public function test_a_booking_paid_at_the_desk_says_the_difference_is_paid_at_reception(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, method: 'cash');
        app(PaymentService::class)->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, null, null, User::factory()->create());

        $this->extend($booking);

        $this->assertStringEndsWith('حتى 12:00. يُدفع المبلغ (100 نقطة) في الاستقبال.', $this->notice($member, 'ar')['body']);
    }

    public function test_an_extension_that_cost_nothing_has_no_payment_line(): void
    {
        $member = $this->member();
        $booking = $this->booking($member, rate: 0);

        $this->extend($booking);

        $this->assertStringEndsWith('has been extended until 12:00.', $this->notice($member, 'en')['body']);
    }
}
