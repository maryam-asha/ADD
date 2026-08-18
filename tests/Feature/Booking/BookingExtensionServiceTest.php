<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingExtensionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingExtensionService $extensions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extensions = app(BookingExtensionService::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function checkedInBooking(array $attributes = []): Booking
    {
        $space = Space::factory()->room()->create([
            'hourly_rate' => '10.00',
            'pricing_currency' => 'USD',
            'slot_granularity_minutes' => 30,
        ]);

        // Normalized to UTC before persisting for the same reason
        // BookingCreationService::create() does: Eloquent's datetime cast
        // formats a stored Carbon using its *own* timezone and reinterprets
        // the raw string as UTC on read (config('app.timezone') is 'UTC' in
        // this app), so a Damascus-timezone Carbon assigned directly would
        // round-trip with a 3-hour drift against the real instant.
        return Booking::factory()->checkedIn()->create(array_merge([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ], $attributes));
    }

    public function test_extending_a_checked_in_booking_with_no_conflict_succeeds(): void
    {
        $booking = $this->checkedInBooking();

        $this->extensions->extend($booking, 60);

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }

    public function test_extension_fails_if_the_booking_is_not_checked_in(): void
    {
        $booking = Booking::factory()->create();

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }

    public function test_extension_fails_if_already_checked_out(): void
    {
        $booking = $this->checkedInBooking(['checked_out_at' => now()]);

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for not checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.not_checked_in', $e->messageKey);
        }
    }

    public function test_extension_fails_when_the_duration_is_below_the_minimum(): void
    {
        $booking = $this->checkedInBooking();

        try {
            $this->extensions->extend($booking, 45);
            $this->fail('Expected a ReceptionActionException for invalid extension duration.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.invalid_extension_duration', $e->messageKey);
        }
    }

    public function test_extension_fails_when_a_conflicting_booking_follows(): void
    {
        $booking = $this->checkedInBooking();
        Booking::factory()->create([
            'space_id' => $booking->space_id,
            'start_at' => Carbon::parse('2026-08-17 11:30:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 12:30:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        try {
            $this->extensions->extend($booking, 60);
            $this->fail('Expected a ReceptionActionException for extension conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.extension_conflict', $e->messageKey);
            // 11:30 Damascus == 08:30 UTC — same instant as the ISO string
            // ReceptionActionException::$params carries (see the note on
            // checkedInBooking() above re: config('app.timezone') = 'UTC').
            $this->assertSame('2026-08-17T08:30:00+00:00', $e->params['latest_end_at']);
        }

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')));
    }

    public function test_extension_debits_the_wallet_when_the_booking_was_paid_by_wallet(): void
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        $booking = $this->checkedInBooking([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->extensions->extend($booking, 60);

        $this->assertSame(1, $wallet->transactions()->where('amount', '-10.00')->count());
        $this->assertSame(PaymentState::Paid, $booking->fresh()->payment_state);
    }

    public function test_extension_leaves_payment_unpaid_when_the_wallet_balance_is_insufficient(): void
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, '5.00', WalletTransactionSource::TopUp);
        $booking = $this->checkedInBooking([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->extensions->extend($booking, 60);

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
        $this->assertSame(0, $wallet->transactions()->where('amount', '<', 0)->count());
    }

    public function test_extension_leaves_payment_unpaid_when_the_member_has_no_personal_wallet(): void
    {
        // The booking was originally paid via a company wallet, so the
        // member never needed a personal one — extend() only ever debits
        // the personal wallet, and walletFor()'s firstOrFail() must not
        // blow up when that wallet simply doesn't exist.
        $member = User::factory()->create();
        $booking = $this->checkedInBooking([
            'user_id' => $member->id,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->extensions->extend($booking, 60);

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
    }

    public function test_extension_leaves_an_unpaid_booking_unpaid(): void
    {
        $booking = $this->checkedInBooking();

        $this->extensions->extend($booking, 60);

        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
    }

    /**
     * The lock-and-recheck lesson, applied to extension: the first request
     * safely extends into the free 11:00-12:00 gap. The second, evaluated
     * after the first's commit, must see the now-current end_at (12:00) —
     * not its own stale in-memory end_at (11:00), against which the same
     * +60-minute request would have wrongly looked free of the booking that
     * starts at 12:00.
     */
    public function test_only_one_of_two_concurrent_extension_requests_for_the_same_following_slot_succeeds(): void
    {
        $booking = $this->checkedInBooking();
        Booking::factory()->create([
            'space_id' => $booking->space_id,
            'start_at' => Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        $first = Booking::find($booking->id);
        $second = Booking::find($booking->id);

        $this->extensions->extend($first, 60);

        try {
            $this->extensions->extend($second, 60);
            $this->fail('Expected a ReceptionActionException for extension conflict.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.extension_conflict', $e->messageKey);
        }

        $this->assertTrue($booking->fresh()->end_at->equalTo(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')));
    }
}
