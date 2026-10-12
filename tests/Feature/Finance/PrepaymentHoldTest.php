<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Finance\Payments\PrepaymentHold;
use App\Domain\Finance\Services\SyncPaymentStatus;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A booking held for its prepayment (awaiting_payment) stops being held when
 * how it is paid is settled — the card goes through, or a method paid later
 * is chosen — and takes no new payment once its deadline has passed
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class PrepaymentHoldTest extends TestCase
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

    public function test_paying_a_hold_by_card_confirms_it(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway();

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');
        $this->assertSame(BookingStatus::AwaitingPayment, $hold->refresh()->status);

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $hold->refresh();
        $this->assertSame(BookingStatus::Confirmed, $hold->status);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_a_hold_on_a_space_needing_approval_goes_to_review_and_staff_hear_of_it_then(): void
    {
        $member = $this->member(300);
        User::factory()->create()->assignRole('operations');
        $hold = $this->hold($member, requiresApproval: true);

        $this->assertSame(0, NotificationLog::where('template_key', 'booking.pending_approval')->count());

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'wallet');

        $this->assertSame(BookingStatus::Pending, $hold->refresh()->status);
        $this->assertSame(1, NotificationLog::where('template_key', 'booking.pending_approval')->count());
    }

    public function test_choosing_cash_releases_the_hold_unpaid(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'cash');

        $hold->refresh();
        $this->assertSame(BookingStatus::Confirmed, $hold->status);
        $this->assertSame(PaymentState::Unpaid, $hold->payment_state);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_no_new_payment_opens_on_an_expired_hold(): void
    {
        $member = $this->member(300);
        $hold = $this->hold($member);
        $hold->forceFill(['payment_due_at' => now()->subMinute()])->save();

        $this->expectException(ReceptionActionException::class);

        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'wallet');
    }

    /**
     * Past the deadline but before the job ran: a card already in flight
     * that the bank accepts pays the hold in full — nothing to undo.
     */
    public function test_a_card_accepted_after_the_deadline_still_releases_the_hold(): void
    {
        $member = $this->member();
        $hold = $this->hold($member);
        $this->fakeGateway();
        app(PayableCheckout::class)->pay(PaymentPurpose::Booking, $hold, $member, 'paymera');

        Carbon::setTestNow(now()->addMinutes(16));
        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(BookingStatus::Confirmed, $hold->refresh()->status);
    }

    /**
     * The caller's copy may be older than the row: the expiry job can cancel
     * the hold between the caller reading it and releasing it. A release
     * decides on the row as it is now, so it cannot bring a cancelled
     * booking back.
     */
    public function test_a_stale_copy_cannot_release_a_hold_that_was_cancelled_meanwhile(): void
    {
        $member = $this->member();
        User::factory()->create()->assignRole('operations');
        $stale = $this->hold($member, requiresApproval: true);

        Booking::query()->whereKey($stale->id)->update(['status' => BookingStatus::Cancelled->value]);
        $this->assertSame(BookingStatus::AwaitingPayment, $stale->status);

        app(PrepaymentHold::class)->release($stale);

        $this->assertSame(BookingStatus::Cancelled, Booking::findOrFail($stale->id)->status);
        $this->assertSame(BookingStatus::Cancelled, $stale->status);
        $this->assertNotNull(Booking::findOrFail($stale->id)->payment_due_at);
        $this->assertSame(0, NotificationLog::where('template_key', 'booking.pending_approval')->count());
    }

    private function registrationHold(User $member): EventAttendee
    {
        $event = Event::create([
            'title' => ['en' => 'Meetup', 'ar' => 'لقاء'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'location' => 'Hall',
            'is_free' => false,
            'price_points' => 100,
            'status' => 'published',
        ]);

        return EventAttendee::create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'name' => 'M',
            'email' => 'm@example.com',
            'status' => EventRegistrationStatus::AwaitingPayment,
            'payment_due_at' => now()->addMinutes(15),
            'price_points' => 100,
            'payment_state' => EventPaymentState::Unpaid,
        ]);
    }

    public function test_paying_a_held_registration_approves_it_and_marks_it_paid(): void
    {
        $member = $this->member(300);
        $hold = $this->registrationHold($member);

        app(PayableCheckout::class)->pay(PaymentPurpose::EventRegistration, $hold, $member, 'wallet');

        $hold->refresh();
        $this->assertSame(EventRegistrationStatus::Approved, $hold->status);
        $this->assertSame(EventPaymentState::Paid, $hold->payment_state);
        $this->assertSame('wallet', $hold->payment_method);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_choosing_cash_releases_a_held_registration_unpaid(): void
    {
        $member = $this->member();
        $hold = $this->registrationHold($member);

        app(PayableCheckout::class)->pay(PaymentPurpose::EventRegistration, $hold, $member, 'cash');

        $hold->refresh();
        $this->assertSame(EventRegistrationStatus::Approved, $hold->status);
        $this->assertSame(EventPaymentState::Unpaid, $hold->payment_state);
        $this->assertNull($hold->payment_due_at);
    }

    public function test_no_new_payment_opens_on_an_expired_registration_hold(): void
    {
        $member = $this->member(300);
        $hold = $this->registrationHold($member);
        $hold->forceFill(['payment_due_at' => now()->subMinute()])->save();

        $this->expectException(ReceptionActionException::class);

        app(PayableCheckout::class)->pay(PaymentPurpose::EventRegistration, $hold, $member, 'wallet');
    }

    public function test_the_deadline_is_the_window_but_never_past_the_start(): void
    {
        $holds = app(PrepaymentHold::class);

        $this->assertTrue($holds->dueAt(now()->addHours(2))->equalTo(now()->addMinutes(15)));
        $this->assertTrue($holds->dueAt(now()->addMinutes(5))->equalTo(now()->addMinutes(5)));
    }
}
