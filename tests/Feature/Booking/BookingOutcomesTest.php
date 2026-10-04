<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a booking becomes once its time has come and gone
 * (docs/decisions/booking-outcomes-and-extension.md): an undecided request
 * lapses when it starts, a booking nobody came to is a no-show when it ends,
 * and a checkout completes it.
 */
class BookingOutcomesTest extends TestCase
{
    use RefreshDatabase;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-08-17 is a Monday; the room's branch is open 08:00–20:00.
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')->utc());
        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $this->room->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A booking from $from to $to o'clock today, Damascus time.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function booking(int $from, int $to, array $attributes = []): Booking
    {
        $day = Carbon::parse('2026-08-17', 'Asia/Damascus');

        return Booking::factory()->create(array_merge([
            'space_id' => $this->room->id,
            'start_at' => $day->copy()->setTime($from, 0)->utc(),
            'end_at' => $day->copy()->setTime($to, 0)->utc(),
        ], $attributes));
    }

    private function walletOf(User $member, int $points = 0): Wallet
    {
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            (new WalletService)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $wallet;
    }

    private function closeElapsed(): void
    {
        $this->artisan('bookings:close-elapsed')->assertSuccessful();
    }

    // ---------------------------------------------------------------
    // A request nobody decided lapses when it starts
    // ---------------------------------------------------------------

    public function test_an_undecided_booking_lapses_to_rejected_once_it_starts(): void
    {
        $booking = $this->booking(12, 13, ['status' => BookingStatus::Pending]);

        $this->closeElapsed();

        $booking->refresh();
        $this->assertSame(BookingStatus::Rejected, $booking->status);
        // Nobody decided it — the clock did.
        $this->assertNull($booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertTrue($booking->lapsedUnreviewed());
    }

    public function test_an_undecided_booking_that_has_not_started_is_left_alone(): void
    {
        $booking = $this->booking(14, 15, ['status' => BookingStatus::Pending]);

        $this->closeElapsed();

        $this->assertSame(BookingStatus::Pending, $booking->fresh()->status);
    }

    public function test_a_lapsed_booking_that_was_paid_gets_its_points_back(): void
    {
        $member = User::factory()->create();
        $wallet = $this->walletOf($member);
        $booking = $this->booking(11, 13, [
            'user_id' => $member->id,
            'status' => BookingStatus::Pending,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->closeElapsed();

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
        $this->assertSame(1, $wallet->transactions()->where('source', WalletTransactionSource::Refund)->count());
    }

    public function test_the_member_is_told_their_request_lapsed(): void
    {
        $booking = $this->booking(12, 13, ['status' => BookingStatus::Pending]);

        $this->closeElapsed();

        $this->assertDatabaseHas(NotificationLog::class, [
            'user_id' => $booking->user_id,
            'template_key' => 'booking.lapsed',
        ]);
    }

    // ---------------------------------------------------------------
    // A booking nobody came to is a no-show when it ends
    // ---------------------------------------------------------------

    public function test_a_confirmed_booking_nobody_checked_into_is_a_no_show_once_it_ends(): void
    {
        $booking = $this->booking(10, 11);

        $this->closeElapsed();

        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->status);
    }

    public function test_a_booking_still_running_is_not_a_no_show(): void
    {
        $booking = $this->booking(11, 13);

        $this->closeElapsed();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_checked_in_booking_is_never_a_no_show_even_past_its_end(): void
    {
        $booking = $this->booking(9, 11, ['checked_in_at' => Carbon::parse('2026-08-17 09:05', 'Asia/Damascus')->utc()]);

        $this->closeElapsed();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_paid_no_show_keeps_its_charge(): void
    {
        $member = User::factory()->create();
        $wallet = $this->walletOf($member);
        $booking = $this->booking(10, 11, [
            'user_id' => $member->id,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'price_points' => 100,
        ]);

        $this->closeElapsed();

        $booking->refresh();
        $this->assertSame(BookingStatus::NoShow, $booking->status);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(0, $wallet->transactions()->where('source', WalletTransactionSource::Refund)->count());
    }

    public function test_an_unpaid_no_show_is_no_longer_owed_at_reception(): void
    {
        $booking = $this->booking(10, 11, ['price_points' => 100]);

        $this->closeElapsed();

        $outstanding = app(OutstandingItems::class)->for($booking->user_id, 'USD');
        $this->assertSame([], $outstanding['items']);
    }

    public function test_an_unpaid_no_show_can_no_longer_be_paid(): void
    {
        $member = User::factory()->create();
        $this->walletOf($member, 500);
        $booking = $this->booking(10, 11, ['user_id' => $member->id, 'price_points' => 100]);

        $this->closeElapsed();

        try {
            app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking->fresh()]);
            $this->fail('Paid for a no-show');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.payment.booking_not_payable', $e->messageKey);
        }
    }

    // ---------------------------------------------------------------
    // A checkout completes the booking
    // ---------------------------------------------------------------

    public function test_a_desk_checkout_completes_the_booking(): void
    {
        $booking = $this->booking(10, 13, ['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()]);

        app(SessionClosureService::class)->closeOut($booking, now());

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_an_automatic_close_completes_the_booking(): void
    {
        $booking = $this->booking(10, 13, ['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()]);

        app(SessionClosureService::class)->autoClose($booking, now());

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
    }

    public function test_a_completed_booking_left_unpaid_is_still_owed(): void
    {
        $booking = $this->booking(10, 13, ['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()]);
        app(SessionClosureService::class)->closeOut($booking, now());

        $outstanding = app(OutstandingItems::class)->for($booking->user_id, 'USD');

        $this->assertSame([$booking->id], array_column($outstanding['items'], 'payable_id'));
    }

    /** Ended statuses still held their slot, so the calendar keeps showing them. */
    public function test_completed_and_no_show_bookings_still_count_as_holding_their_slot(): void
    {
        $completed = $this->booking(8, 9, ['status' => BookingStatus::Completed]);
        $noShow = $this->booking(9, 10, ['status' => BookingStatus::NoShow]);
        $this->booking(10, 11, ['status' => BookingStatus::Cancelled]);

        $this->assertEqualsCanonicalizing(
            [$completed->id, $noShow->id],
            Booking::query()->blocking()->pluck('id')->all(),
        );
    }

    // ---------------------------------------------------------------
    // Ended bookings refuse the actions of an open one
    // ---------------------------------------------------------------

    public function test_a_rejected_booking_cannot_be_cancelled(): void
    {
        $booking = $this->booking(16, 17, ['status' => BookingStatus::Rejected]);

        try {
            app(BookingCancellationService::class)->cancel($booking);
            $this->fail('Cancelled a rejected booking');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.not_cancellable', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(BookingStatus::Rejected, $booking->fresh()->status);
    }

    public function test_a_no_show_cannot_be_checked_in(): void
    {
        $booking = $this->booking(10, 11, ['status' => BookingStatus::NoShow]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in a no-show');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_no_show', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    /** Before the job marks it, an ended booking is refused all the same — the two never race. */
    public function test_a_booking_cannot_be_checked_in_once_it_has_ended(): void
    {
        $booking = $this->booking(10, 11);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked in an ended booking');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_ended', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }
}
