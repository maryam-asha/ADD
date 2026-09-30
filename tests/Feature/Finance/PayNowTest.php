<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
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
 * Paying for something already made — a booking left unpaid, a closed
 * coworking session — from the app, by points or by card
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 7).
 */
class PayNowTest extends TestCase
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
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeGateway(string $status = 'A', int $amountMinor = 1000): void
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
                    'amount' => $amountMinor,
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

    private function walletOf(User $member): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
    }

    private function space(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function unpaidBooking(User $member): Booking
    {
        return Booking::factory()->create([
            'space_id' => $this->space()->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
    }

    private function payNow(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', $body);
    }

    public function test_the_old_top_up_request_answers_as_before_with_next(): void
    {
        Sanctum::actingAs($this->member(), ['*']);
        $this->fakeGateway();

        $this->payNow(['purpose' => 'wallet_top_up', 'amount' => '10.00'])
            ->assertCreated()
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-9/en')
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.next', 'redirect');
    }

    public function test_an_unpaid_booking_is_paid_now_from_the_wallet(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);

        $this->payNow(['purpose' => 'booking', 'method' => 'wallet', 'payable_id' => $booking->id])
            ->assertCreated()
            ->assertJsonPath('data.next', 'done')
            ->assertJsonPath('data.redirect_url', null)
            ->assertJsonPath('data.payment.status', 'accepted');

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(200, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_an_unpaid_booking_paid_by_card_is_paid_once_the_gateway_accepts(): void
    {
        $member = $this->member();
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway();

        $this->payNow(['purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-9/en');

        $payment = Payment::sole();
        $this->assertSame(100, $payment->points_to_credit);
        $this->assertSame(1000, $payment->amount_minor);
        $this->assertSame($this->walletOf($member)->id, $payment->wallet_id);
        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);

        app(SyncPaymentStatus::class)->handle($payment);

        $booking->refresh();
        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Gateway, $booking->payment_source);
        $this->assertSame(PaymentMethod::Paymera, $booking->payment_method);
        // Credited then spent: nothing left over.
        $this->assertSame(0, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_declined_card_leaves_the_booking_unpaid(): void
    {
        $member = $this->member();
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway('F');

        $this->payNow(['purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id])->assertCreated();
        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);
    }

    /**
     * Money that bought points is spent as those points, never from an
     * allowance the member's plan gave them for something else.
     */
    public function test_a_card_payment_does_not_spend_the_members_plan_allowance(): void
    {
        $member = $this->member();
        app(WalletService::class)->creditCategorized(
            $this->walletOf($member),
            WalletTransactionCategory::SpaceSpecific,
            500,
            WalletTransactionSource::SubscriptionGrant,
            now()->addMonth(),
            null,
            [],
            'grant',
        );
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway();

        $this->payNow(['purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id])->assertCreated();
        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(0, app(WalletService::class)->generalBalance($this->walletOf($member)));
        $this->assertSame(500, app(WalletService::class)->categoryBalance($this->walletOf($member), $member, WalletTransactionCategory::SpaceSpecific));
    }

    public function test_someone_elses_booking_cannot_be_paid(): void
    {
        $booking = $this->unpaidBooking($this->member());
        Sanctum::actingAs($this->member(300), ['*']);

        $this->payNow(['purpose' => 'booking', 'method' => 'wallet', 'payable_id' => $booking->id])->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    public function test_a_paid_booking_cannot_be_paid_again(): void
    {
        $member = $this->member(300);
        $booking = $this->unpaidBooking($member);
        $booking->forceFill(['payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Cash])->save();
        Sanctum::actingAs($member, ['*']);

        $this->payNow(['purpose' => 'booking', 'method' => 'wallet', 'payable_id' => $booking->id])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.payment.already_paid', [], 'en'));
    }

    public function test_an_open_session_cannot_be_paid_but_a_closed_one_can(): void
    {
        $member = $this->member(300);
        Sanctum::actingAs($member, ['*']);

        $open = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null]);
        $this->payNow(['purpose' => 'coworking_session', 'method' => 'wallet', 'payable_id' => $open->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.reception.not_yet_checked_out', [], 'en'));

        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'price_points' => 150,
            'amount_owed' => '15.00',
            'payment_state' => PaymentState::Unpaid,
        ]);
        $this->payNow(['purpose' => 'coworking_session', 'method' => 'wallet', 'payable_id' => $closed->id])
            ->assertCreated()
            ->assertJsonPath('data.next', 'done');

        $this->assertSame(PaymentState::Paid, $closed->refresh()->payment_state);
        $this->assertSame(150, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_card_accepted_after_the_desk_took_the_money_keeps_the_points_for_review(): void
    {
        $member = $this->member();
        $booking = $this->unpaidBooking($member);
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway();

        $this->payNow(['purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id])->assertCreated();

        // Paid at the desk while the member was on the card page.
        $booking->forceFill(['payment_state' => PaymentState::Paid, 'payment_source' => PaymentSource::Cash])->save();

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::NeedsReview, $payment->status);
        $this->assertSame('payable already settled — points kept in wallet', $payment->review_reason);
        $this->assertSame(100, app(WalletService::class)->generalBalance($this->walletOf($member)));
    }

    public function test_a_booking_made_with_card_is_unpaid_and_says_where_to_pay(): void
    {
        $member = $this->member();
        $space = $this->space();
        Sanctum::actingAs($member, ['*']);
        $this->fakeGateway();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])
            ->assertCreated()
            ->assertJsonPath('redirect_url', 'https://egate-t.paymera.test/start/pay-9/en')
            ->assertJsonPath('payment.method', 'paymera');

        $this->assertSame(PaymentState::Unpaid, Booking::sole()->payment_state);
        $this->assertSame('booking', Payment::sole()->payable_type);
    }

    public function test_a_booking_made_with_card_survives_the_gateway_being_down(): void
    {
        $member = $this->member();
        $space = $this->space();
        Sanctum::actingAs($member, ['*']);
        Http::fake(['*/api/create-payment' => Http::response(['ErrorMessage' => 'Down', 'ErrorCode' => 500], 500)]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'paymera',
        ])
            ->assertCreated()
            ->assertJsonPath('payment', null)
            ->assertJsonPath('payment_error', __('api.payment.could_not_start', [], 'en'));

        $this->assertSame(PaymentState::Unpaid, Booking::sole()->payment_state);
    }
}
