<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An extension is paid by the method chosen for it, never one assumed from
 * how the booking itself was paid: the member names it (wallet or card),
 * reception may name wallet or cash or leave it owed.
 */
class ExtensionPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

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
            'paymera.default_lang' => 'en',
        ]);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'creationTimestamp' => '2026-08-17 08:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => 10,
                    'terminalId' => '99990001',
                ],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points): User
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

    /**
     * 10:00-11:00 at 100 points an hour, paid from the wallet, checked in;
     * now 10:30.
     */
    private function walletPaidRunningBooking(User $member): Booking
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'price_points' => 100,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'chosen_payment_method' => 'wallet',
        ]);

        app(PaymentService::class)->start(PaymentPurpose::Booking, $member, 'wallet', ['payable' => $booking]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')->utc());
        $booking->forceFill(['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()])->save();

        return $booking->refresh();
    }

    /** @param  array<string, mixed>  $body */
    private function memberExtends(User $member, Booking $booking, array $body): TestResponse
    {
        Sanctum::actingAs($member, ['*']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", ['additional_minutes' => 60] + $body);
    }

    /** @param  array<string, mixed>  $body */
    private function receptionExtends(Booking $booking, array $body = []): TestResponse
    {
        $operator = User::factory()->create();
        $operator->assignRole('admin');
        Sanctum::actingAs($operator, ['*']);

        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/extend", ['additional_minutes' => 60] + $body);
    }

    private function assertNotExtended(Booking $booking): void
    {
        $booking->refresh();
        $this->assertTrue($booking->end_at->equalTo(Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')));
        $this->assertSame(100, $booking->price_points);
    }

    public function test_the_member_must_choose_how_to_pay_an_extension_that_costs_anything(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->memberExtends($member, $booking, [])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.booking.payment_method_required', [], 'en'));

        $this->assertNotExtended($booking);
        $this->assertSame(900, $this->balanceOf($member));
    }

    public function test_the_member_cannot_choose_cash_for_an_extension(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->memberExtends($member, $booking, ['payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertNotExtended($booking);
    }

    public function test_choosing_the_wallet_pays_the_extension_with_it(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->memberExtends($member, $booking, ['payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('message', 'Booking extended.')
            ->assertJsonPath('data.next', 'done')
            ->assertJsonPath('data.redirect_url', null)
            ->assertJsonPath('data.payment.amount_points', 100);

        $booking->refresh();
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(800, $this->balanceOf($member));
    }

    public function test_a_wallet_too_short_refuses_the_extension_rather_than_leaving_it_owed(): void
    {
        $member = $this->member(150);
        $booking = $this->walletPaidRunningBooking($member);

        $this->memberExtends($member, $booking, ['payment_method' => 'wallet'])->assertUnprocessable();

        $this->assertNotExtended($booking);
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(50, $this->balanceOf($member));
        $this->assertSame(0, Payment::query()->where('purpose', PaymentPurpose::BookingExtension)->count());
    }

    public function test_choosing_the_card_leaves_the_wallet_alone_even_on_a_wallet_paid_booking(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->memberExtends($member, $booking, ['payment_method' => 'paymera'])
            ->assertOk()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-1/en')
            ->assertJsonPath('data.payment.amount_points', 100);

        $booking->refresh();
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame('paymera', $booking->chosen_payment_method);
        $this->assertSame(900, $this->balanceOf($member));

        // The bank pays it: the booking is paid again, the wallet untouched.
        $card = Payment::query()->where('purpose', PaymentPurpose::BookingExtension)->sole();
        app(SyncPaymentStatus::class)->handle($card);

        $this->assertSame(PaymentStatus::Accepted, $card->refresh()->status);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(900, $this->balanceOf($member));
    }

    public function test_reception_without_a_method_leaves_the_extension_owed_instead_of_charging_the_wallet(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->receptionExtends($booking)->assertOk();

        $booking->refresh();
        $this->assertSame(200, $booking->price_points);
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame(900, $this->balanceOf($member));
    }

    public function test_reception_choosing_cash_leaves_it_to_the_desk(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->receptionExtends($booking, ['payment_method' => 'cash'])->assertOk();

        $booking->refresh();
        $this->assertSame(PaymentState::Unpaid, $booking->payment_state);
        $this->assertSame('cash', $booking->chosen_payment_method);
        $this->assertSame(900, $this->balanceOf($member));
    }

    public function test_reception_choosing_the_wallet_charges_it(): void
    {
        $member = $this->member(1000);
        $booking = $this->walletPaidRunningBooking($member);

        $this->receptionExtends($booking, ['payment_method' => 'wallet'])->assertOk();

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(800, $this->balanceOf($member));
    }

    public function test_the_app_lists_the_methods_an_extension_can_be_paid_by(): void
    {
        $member = $this->member(1000);
        Sanctum::actingAs($member, ['*']);

        $methods = collect($this->withHeader('lang', 'en')
            ->getJson('/api/v1/member/payment-methods?purpose=booking_extension')
            ->assertOk()
            ->json('data.methods'))->keyBy('key');

        $this->assertTrue($methods['wallet']['available']);
        $this->assertTrue($methods['paymera']['available']);
        $this->assertFalse($methods['cash']['available']);
        $this->assertSame(__('api.payment.reception_only_hints.booking_extension', [], 'en'), $methods['cash']['hint']);
    }
}
