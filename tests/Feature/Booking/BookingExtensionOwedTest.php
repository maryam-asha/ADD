<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a booking owes once part of it is paid (final review C2): its price
 * less every accepted, unrefunded payment made for it — the booking itself
 * and any extension. Extending a paid booking leaves only the extension
 * owing; nothing already paid is billed again, at the desk, by card, or at
 * check-out.
 */
class BookingExtensionOwedTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');

        $this->fakeGateway();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Every card payment here charges 1000 minor units (100 points); each
     * create gets its own gateway id.
     */
    private function fakeGateway(): void
    {
        $created = fn (string $id) => [
            'ErrorMessage' => 'Success',
            'ErrorCode' => 0,
            'Data' => ['url' => "https://egate-t.paymera.test/start/{$id}/en", 'paymentId' => $id],
        ];

        Http::fake([
            '*/api/create-payment' => Http::sequence()->push($created('pay-1'))->push($created('pay-2')),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'creationTimestamp' => '2026-08-17 08:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => 1000,
                    'terminalId' => '99990001',
                ],
            ]),
        ]);
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

    private function balanceOf(User $member): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        );
    }

    /** 10:00-11:00 at 100 points an hour, unpaid. */
    private function booking(User $member): Booking
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
            'amount_owed' => '10',
            'price_points' => 100,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
        ]);
    }

    private function payByCard(Booking $booking, User $member): Payment
    {
        ['payment' => $payment] = app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'paymera', ['payable' => $booking]);
        app(SyncPaymentStatus::class)->handle($payment);

        return $payment->refresh();
    }

    private function checkInAndExtend(Booking $booking, int $minutes = 60): Booking
    {
        $booking->forceFill(['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()])->save();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')->utc());

        app(BookingExtensionService::class)->extend($booking, $minutes);

        return $booking->refresh();
    }

    private function owedPoints(User $member): int
    {
        return app(OutstandingItems::class)->for($member->id, 'USD')['total_points'];
    }

    public function test_a_card_paid_booking_extended_by_an_hour_owes_exactly_that_hour(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        $this->assertSame(PaymentStatus::Accepted, $this->payByCard($booking, $member)->status);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);

        $booking = $this->checkInAndExtend($booking);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(100, $this->owedPoints($member));

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            // The detail carries the state; what is owed is payment-methods'
            // figure (the detail's `payment` block was retired on
            // feat/add-point-system in favour of payment_state/payment_id).
            ->assertJsonPath('data.payment_state', 'unpaid')
            ->assertJsonPath('data.total.points', 200);
        $this->withHeader('lang', 'en')->getJson("/api/v1/member/payment-methods?purpose=booking&payable_id={$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.amount_points', 100);

        // Pay-now by card charges the hour, not the booking again.
        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'booking_id' => $booking->id, 'payment_method' => 'paymera',
        ])->assertOk();

        $second = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame(100, $second->amount_points);
        $this->assertSame(1000, $second->amount_minor);

        app(SyncPaymentStatus::class)->handle($second);

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(0, $this->owedPoints($member));
        $this->assertSame(0, $this->balanceOf($member));
    }

    public function test_a_desk_paid_booking_extended_is_settled_at_reception_for_only_the_difference(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        app(PaymentService::class)->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, null, null, $this->operator);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);

        $booking = $this->checkInAndExtend($booking);
        $this->assertSame(100, $this->owedPoints($member));

        Sanctum::actingAs($this->operator, ['*']);
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.points_charged', 100)
            ->assertJsonPath('data.payment.received_amount', '10');

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(0, $this->owedPoints($member));
        $this->assertSame(0, $this->balanceOf($member));
    }

    public function test_a_desk_paid_extended_booking_closed_at_the_desk_is_billed_only_the_rest(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        app(PaymentService::class)->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, null, null, $this->operator);
        $booking = $this->checkInAndExtend($booking);

        Carbon::setTestNow(Carbon::parse('2026-08-17 12:05:00', 'Asia/Damascus')->utc());
        app(SessionClosureService::class)->closeOut($booking, Carbon::parse('2026-08-17 12:00', 'Asia/Damascus'));
        $this->assertSame(100, $this->owedPoints($member));

        // The old endpoint, "exactly what is owed".
        Sanctum::actingAs($this->operator, ['*']);
        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", ['payment_method' => 'cash'])
            ->assertOk();

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame([100, 100], Payment::query()->forPayable($booking)->orderBy('id')->pluck('amount_points')->all());
        $this->assertSame(0, $this->balanceOf($member));
    }

    public function test_a_wallet_paid_booking_extension_still_charges_the_wallet_only_the_difference(): void
    {
        $member = $this->member(1000);
        $booking = $this->booking($member);
        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking]);
        $this->assertSame(900, $this->balanceOf($member));

        $booking = $this->checkInAndExtend($booking);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Wallet, $booking->payment_source);
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(800, $this->balanceOf($member));
        $this->assertSame(0, $this->owedPoints($member));

        $extension = Payment::query()->where('purpose', PaymentPurpose::BookingExtension)->sole();
        $this->assertSame(100, $extension->amount_points);
    }

    public function test_a_wallet_paid_booking_whose_wallet_cannot_cover_the_extension_owes_only_the_extension(): void
    {
        $member = $this->member(100);
        $booking = $this->booking($member);
        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking]);
        $this->assertSame(0, $this->balanceOf($member));

        $booking = $this->checkInAndExtend($booking);

        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(100, $this->owedPoints($member));
    }

    public function test_closing_a_fully_paid_booking_does_not_change_what_is_owed(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        $this->payByCard($booking, $member);
        $booking->forceFill(['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()])->save();

        Carbon::setTestNow(Carbon::parse('2026-08-17 11:35:00', 'Asia/Damascus')->utc());
        app(SessionClosureService::class)->closeOut($booking, Carbon::parse('2026-08-17 11:30', 'Asia/Damascus'));

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(100, $booking->price_points);
        $this->assertSame(0, $this->owedPoints($member));
    }

    /**
     * Extended, then left early: what was already paid covers the time
     * used, so the booking is paid again and nothing is owed.
     */
    public function test_an_extended_booking_left_before_the_extension_owes_nothing_more(): void
    {
        $member = $this->member();
        $booking = $this->booking($member);
        $this->payByCard($booking, $member);
        $booking = $this->checkInAndExtend($booking);
        $this->assertSame(100, $this->owedPoints($member));

        Carbon::setTestNow(Carbon::parse('2026-08-17 11:05:00', 'Asia/Damascus')->utc());
        app(SessionClosureService::class)->closeOut($booking, Carbon::parse('2026-08-17 11:00', 'Asia/Damascus'));

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(0, $this->owedPoints($member));
    }
}
