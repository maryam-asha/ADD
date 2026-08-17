<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingCancellationService $cancellations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cancellations = app(BookingCancellationService::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelling_within_the_global_window_succeeds(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        $this->cancellations->cancel($booking);

        $booking->refresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
    }

    public function test_cancelling_a_wallet_paid_booking_refunds_the_planned_amount(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(4),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $this->cancellations->cancel($booking);

        $refund = $wallet->transactions()
            ->where('source', WalletTransactionSource::Refund)
            ->where('category', WalletTransactionCategory::General)
            ->first();

        $this->assertNotNull($refund);
        $this->assertSame('20.00', (string) $refund->amount);
    }

    public function test_cancelling_a_cash_paid_booking_does_not_touch_any_wallet(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00']);
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Cash,
        ]);

        $this->cancellations->cancel($booking);

        $this->assertSame(0, $wallet->transactions()->count());
    }

    public function test_cancelling_past_the_window_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for past the cancellation window.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.cancellation_window_passed', $e->messageKey);
        }

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_per_space_window_override_takes_precedence_over_the_global_default(): void
    {
        // Global default is 60 minutes (SettingSeeder); this space overrides to 15.
        $space = Space::factory()->room()->create(['cancellation_window_minutes' => 15]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(30),
            'end_at' => now()->addHours(1),
        ]);

        $this->cancellations->cancel($booking);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancelling_an_already_checked_in_booking_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
        ]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for already checked in.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_in', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }

    public function test_cancelling_an_already_cancelled_booking_fails(): void
    {
        $space = Space::factory()->room()->create();
        $booking = Booking::factory()->cancelled()->create(['space_id' => $space->id]);

        try {
            $this->cancellations->cancel($booking);
            $this->fail('Expected a ReceptionActionException for already cancelled.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_cancelled', $e->messageKey);
            $this->assertSame(409, $e->status);
        }
    }
}
