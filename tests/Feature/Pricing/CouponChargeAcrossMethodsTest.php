<?php

namespace Tests\Feature\Pricing;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whatever the method, a coupon sent with a payment is taken off what is
 * charged: the points debited from the wallet, the money sent to the card
 * gateway, and what reception collects. A point is 0.10 USD and 1 USD is
 * 10,000 SYP, so every point is 1,000 SYP on the card.
 */
class CouponChargeAcrossMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const SYP_PER_POINT = 1000;

    private User $member;

    private Wallet $wallet;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'SYP',
            'paymera.default_lang' => 'en',
        ]);
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $this->wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($this->wallet, 1000, WalletTransactionSource::TopUp);
        Sanctum::actingAs($this->member, ['member-app']);

        Coupon::factory()->percentage(20)->create(['code' => 'OFF20']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unpaidBooking(): Booking
    {
        return Booking::factory()->create([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'status' => BookingStatus::Confirmed,
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
            'list_price_points' => 100,
            'amount_owed' => '10',
        ]);
    }

    private function closedSession(): WalkinSession
    {
        return WalkinSession::factory()->create([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'checked_in_at' => now()->subHour(),
            'checked_out_at' => now(),
            'payment_state' => PaymentState::Unpaid,
            'price_points' => 100,
            'list_price_points' => 100,
            'amount_owed' => '10',
        ]);
    }

    private function balance(): int
    {
        return app(WalletService::class)->generalBalance($this->wallet->refresh());
    }

    private function assertCardChargedFor(int $points): void
    {
        $payment = Payment::sole();

        $this->assertSame($points, $payment->amount_points);
        $this->assertSame($points * self::SYP_PER_POINT, $payment->charged_amount);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'create-payment')
            && $request['amount'] === $points * self::SYP_PER_POINT);
    }

    public function test_an_existing_booking_paid_by_card_is_charged_its_coupon_price(): void
    {
        $booking = $this->unpaidBooking();

        $this->postJson('/api/v1/member/bookings', ['booking_id' => $booking->id, 'payment_method' => 'paymera', 'coupon_code' => 'OFF20'])
            ->assertOk()
            ->assertJsonPath('coupon.applied', true);

        $this->assertSame(80, $booking->refresh()->price_points);
        $this->assertCardChargedFor(80);
    }

    public function test_a_session_paid_from_the_wallet_debits_its_coupon_price(): void
    {
        $session = $this->closedSession();

        $this->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay", ['payment_method' => 'wallet', 'coupon_code' => 'OFF20'])
            ->assertCreated();

        $this->assertSame(80, Payment::sole()->amount_points);
        $this->assertSame(920, $this->balance());
    }

    public function test_a_session_paid_by_card_is_charged_its_coupon_price(): void
    {
        $session = $this->closedSession();

        $this->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay", ['payment_method' => 'paymera', 'coupon_code' => 'OFF20'])
            ->assertCreated();

        $this->assertCardChargedFor(80);
    }

    public function test_an_event_registered_with_a_card_is_charged_its_coupon_price(): void
    {
        Coupon::factory()->percentage(30)->forEvents()->create(['code' => 'EVENT30']);
        $event = Event::create([
            'title' => ['en' => 'Masterclass', 'ar' => 'دورة'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 20,
            'is_free' => false,
            'price_points' => 100,
            'status' => 'published',
            'requires_approval' => false,
        ]);

        $this->postJson("/api/v1/member/events/{$event->id}/register", ['payment_method' => 'paymera', 'coupon_code' => 'EVENT30'])
            ->assertCreated();

        $this->assertSame(70, EventAttendee::sole()->price_points);
        $this->assertCardChargedFor(70);
        $this->assertSame(1000, $this->balance());
    }

    /** Cash: the coupon lowers what is owed now, and reception collects that. */
    public function test_a_booking_left_for_cash_is_collected_at_its_coupon_price(): void
    {
        $booking = $this->unpaidBooking();

        $this->postJson('/api/v1/member/bookings', ['booking_id' => $booking->id, 'payment_method' => 'cash', 'coupon_code' => 'OFF20'])
            ->assertOk()
            ->assertJsonPath('coupon.applied', true);

        $this->assertSame(80, $booking->refresh()->price_points);
        $this->assertSame('8', (string) $booking->amount_owed);
        $this->assertSame(0, Payment::count());

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        // 7 USD is short of the 8 the coupon left; 8 settles it.
        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => '7',
        ])->assertStatus(422)->assertJsonPath('shortage', '1');

        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => '8',
        ])->assertSuccessful();

        $this->assertSame(80, Payment::sole()->amount_points);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(1000, $this->balance());
    }
}
