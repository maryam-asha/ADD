<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A booking or registration held for its prepayment is let go once its
 * deadline has passed — after one last word from the gateway, so a card the
 * bank accepted in the meantime pays it instead
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class CancelExpiredPrepaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => self::TERMINAL,
            'paymera.currency' => 'USD',
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeGateway(string $status = 'A', int $chargedAmount = 10): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => $status,
                    'creationTimestamp' => '2026-08-17 08:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => $chargedAmount,
                    'terminalId' => self::TERMINAL,
                ],
            ]),
        ]);
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

    private function hold(User $member, bool $requiresApproval = false): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'status' => BookingStatus::AwaitingPayment,
            'required_approval' => $requiresApproval,
            'payment_due_at' => now()->addMinutes(15),
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(3),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
        ]);
    }

    public function test_an_expired_hold_is_cancelled_and_the_member_told(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $hold->refresh();
        $this->assertSame(BookingStatus::Cancelled, $hold->status);
        $this->assertTrue($hold->cancelledAwaitingPayment());
        $this->assertSame(1, NotificationLog::where('user_id', $member->id)->where('template_key', 'booking.prepayment_expired')->count());
    }

    public function test_a_hold_whose_card_went_through_is_kept(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway('A');
        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(BookingStatus::Confirmed, $hold->refresh()->status);
        $this->assertSame(PaymentState::Paid, $hold->payment_state);
        $this->assertSame(0, NotificationLog::where('template_key', 'booking.prepayment_expired')->count());
    }

    public function test_a_gateway_that_cannot_be_reached_does_not_keep_the_hold_alive(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway('A');
        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');
        Http::fake(['*/api/get-payment-status/*' => fn () => throw new ConnectionException('timed out')]);
        Carbon::setTestNow(now()->addMinutes(16));

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $hold->refresh()->status);
    }

    public function test_live_holds_and_ordinary_bookings_are_left_alone(): void
    {
        $member = $this->member();
        $live = $this->hold($member);
        $ordinary = Booking::factory()->create(['user_id' => $member->id, 'status' => BookingStatus::Confirmed, 'payment_state' => PaymentState::Unpaid]);

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(BookingStatus::AwaitingPayment, $live->refresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $ordinary->refresh()->status);
    }

    public function test_an_expired_registration_hold_is_rejected_with_its_reason(): void
    {
        $member = $this->member();
        $attendee = EventAttendee::factory()->create([
            'user_id' => $member->id,
            'status' => EventRegistrationStatus::AwaitingPayment,
            'payment_due_at' => now()->subMinute(),
            'price_points' => 80,
            'payment_state' => EventPaymentState::Unpaid,
        ]);

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Rejected, $attendee->status);
        $this->assertSame(__('api.event.prepayment_expired'), $attendee->rejection_reason);
        $this->assertFalse($attendee->status->holdsSeat());
        $this->assertSame(1, NotificationLog::where('user_id', $member->id)->where('template_key', 'event.registration_prepayment_expired')->count());
    }

    public function test_a_live_registration_hold_is_left_alone(): void
    {
        $member = $this->member();
        $attendee = EventAttendee::factory()->create([
            'user_id' => $member->id,
            'status' => EventRegistrationStatus::AwaitingPayment,
            'payment_due_at' => now()->addMinutes(5),
            'price_points' => 80,
            'payment_state' => EventPaymentState::Unpaid,
        ]);

        $this->artisan('payments:cancel-expired-prepayments')->assertSuccessful();

        $this->assertSame(EventRegistrationStatus::AwaitingPayment, $attendee->refresh()->status);
    }

    public function test_it_runs_every_minute_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'payments:cancel-expired-prepayments'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
