<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\CoworkingPaymentAtReception;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * What a booking still owes when it closes is paid by the method it was
 * booked with: the member's own points at once, the desk told to collect
 * cash, a card left for the member to pay from the app. A wallet that
 * falls short leaves it owed — the booking closes either way.
 */
class ClosedBookingSettlementTest extends TestCase
{
    use RefreshDatabase;

    private SessionClosureService $closures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow($this->at('13:00'));
        $this->closures = app(SessionClosureService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
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

    private function balanceOf(User $member): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        );
    }

    private function space(SpaceType $type = SpaceType::Room): Space
    {
        $space = Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /**
     * A room 10:00–12:00 at 300 points, checked in at 10:00, of which
     * $paid were paid when it was booked (the rest is an extension).
     */
    private function room(User $member, string $method, int $paid = 200): Booking
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->space()->id,
            'user_id' => $member->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('12:00'),
            'checked_in_at' => $this->at('10:00'),
            'price_points' => 300,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => $paid > 0 ? PaymentSource::Wallet : null,
            'chosen_payment_method' => $method,
        ]);

        if ($paid > 0) {
            Payment::factory()->forPayable($booking)->create([
                'purpose' => PaymentPurpose::Booking,
                'status' => PaymentStatus::Accepted,
                'settled_at' => $this->at('09:00'),
                'amount_points' => $paid,
                'user_id' => $member->id,
            ]);
        }

        return $booking;
    }

    public function test_a_wallet_room_owing_its_extension_is_paid_from_the_wallet_on_close(): void
    {
        $member = $this->member(500);
        $booking = $this->room($member, 'wallet');

        $this->closures->closeOut($booking, $this->at('12:00'));

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(400, $this->balanceOf($member));
        $this->assertSame(300, $booking->pointsPaid());
    }

    public function test_a_short_wallet_leaves_it_owed_and_the_room_closed(): void
    {
        $member = $this->member(50);
        $booking = $this->room($member, 'wallet');

        $this->closures->closeOut($booking, $this->at('12:00'));

        $booking->refresh();
        $this->assertSame(BookingStatus::Completed, $booking->status);
        $this->assertNotNull($booking->checked_out_at);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(50, $this->balanceOf($member));
        $this->assertSame(1, Payment::query()->where('status', PaymentStatus::Accepted)->count());
    }

    public function test_a_cash_room_owing_on_close_tells_the_desk(): void
    {
        Event::fake([CoworkingPaymentAtReception::class]);
        $member = $this->member();
        $booking = $this->room($member, 'cash', paid: 0);

        $this->closures->closeOut($booking, $this->at('12:00'));

        Event::assertDispatchedTimes(CoworkingPaymentAtReception::class, 1);
        Event::assertDispatched(CoworkingPaymentAtReception::class, fn ($event) => $event->sessionId === $booking->id);
        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
    }

    public function test_a_card_room_owing_on_close_waits_for_the_member(): void
    {
        Event::fake([CoworkingPaymentAtReception::class]);
        $member = $this->member(500);
        $booking = $this->room($member, 'paymera');

        $this->closures->closeOut($booking, $this->at('12:00'));

        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
        $this->assertSame(1, Payment::count());
        $this->assertSame(500, $this->balanceOf($member));
        Event::assertNotDispatched(CoworkingPaymentAtReception::class);
    }

    public function test_a_paid_room_closes_without_any_payment(): void
    {
        $member = $this->member(500);
        $booking = $this->room($member, 'wallet', paid: 300);
        $booking->forceFill(['payment_state' => PaymentState::Paid, 'paid_at' => $this->at('09:00')])->save();

        $this->closures->closeOut($booking, $this->at('11:00'));

        $this->assertSame(1, Payment::count());
        $this->assertSame(500, $this->balanceOf($member));
    }

    public function test_a_wallet_workspace_booking_overstay_is_paid_from_the_wallet_on_close(): void
    {
        $member = $this->member(500);
        $booking = Booking::factory()->create([
            'space_id' => $this->space(SpaceType::CoSpace)->id,
            'user_id' => $member->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('11:00'),
            'checked_in_at' => $this->at('10:00'),
            'price_points' => 100,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'paid_at' => $this->at('09:00'),
            'chosen_payment_method' => 'wallet',
        ]);
        Payment::factory()->forPayable($booking)->create([
            'purpose' => PaymentPurpose::Booking,
            'status' => PaymentStatus::Accepted,
            'settled_at' => $this->at('09:00'),
            'amount_points' => 100,
            'user_id' => $member->id,
        ]);

        // Half an hour past its end: 50 points more.
        $this->closures->closeOut($booking, $this->at('11:30'));

        $booking->refresh();
        $this->assertSame(150, $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(450, $this->balanceOf($member));
    }
}
