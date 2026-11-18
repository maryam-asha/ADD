<?php

namespace Tests\Feature\Member;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\CoworkingPaymentAtReception;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST member/bookings/{booking}/pay: what a closed booking still owes,
 * paid from its home card — by the method it was booked with unless the
 * member names another. The coworking session pay endpoint falls back to
 * that method for a booking too.
 */
class PayBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 13:00:00', 'Asia/Damascus')->utc());

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
        ]);

        $this->member = $this->memberWith(0);
        Sanctum::actingAs($this->member, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function memberWith(int $points): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    /** A room booking closed at noon, owing all of its 200 points. */
    private function closedRoom(?string $method, array $attributes = [], SpaceType $type = SpaceType::Room): Booking
    {
        return Booking::factory()->create([
            'space_id' => Space::factory()->create(['space_type' => $type, 'hourly_rate_points' => 100])->id,
            'user_id' => $this->member->id,
            'status' => BookingStatus::Completed,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'checked_out_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'price_points' => 200,
            'amount_owed' => '20',
            'payment_state' => PaymentState::Unpaid,
            'chosen_payment_method' => $method,
            ...$attributes,
        ]);
    }

    private function pay(Booking $booking, array $body = []): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/pay", $body);
    }

    public function test_a_card_booking_is_paid_by_card_without_choosing_again(): void
    {
        $booking = $this->closedRoom('paymera');

        $this->pay($booking)
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-1/en')
            ->assertJsonPath('data.payment.method', 'paymera');
    }

    public function test_a_cash_booking_tells_the_desk(): void
    {
        Event::fake([CoworkingPaymentAtReception::class]);
        $booking = $this->closedRoom('cash');

        $this->pay($booking)->assertCreated()->assertJsonPath('data.next', 'pay_at_reception');

        Event::assertDispatched(CoworkingPaymentAtReception::class, fn ($e) => $e->sessionType === 'booking' && $e->sessionId === $booking->id);
        $this->assertSame(PaymentState::Unpaid, $booking->fresh()->payment_state);
    }

    /** The wallet fell short when the room closed; topped up since, the member pays from it. */
    public function test_another_method_may_be_named_and_is_kept(): void
    {
        $booking = $this->closedRoom('cash');
        app(WalletService::class)->creditGeneral(Wallet::where('owner_id', $this->member->id)->firstOrFail(), 500, WalletTransactionSource::TopUp);

        $this->pay($booking, ['payment_method' => 'wallet'])->assertCreated()->assertJsonPath('data.next', 'done');

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame('wallet', $booking->chosen_payment_method);
    }

    public function test_a_booking_with_no_method_kept_asks_for_one(): void
    {
        $this->pay($this->closedRoom(null))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose how to pay for this booking.');
    }

    public function test_someone_elses_booking_is_not_found(): void
    {
        $booking = $this->closedRoom('cash', ['user_id' => $this->memberWith(0)->id]);

        $this->pay($booking)->assertNotFound();
    }

    public function test_a_booking_still_running_is_paid_another_way(): void
    {
        $booking = $this->closedRoom('cash', ['status' => BookingStatus::Confirmed, 'checked_out_at' => null]);

        $this->pay($booking)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This booking or session must be checked out before payment can be settled.');
    }

    public function test_the_coworking_pay_endpoint_pays_a_booking_by_its_kept_method(): void
    {
        $booking = $this->closedRoom('cash', type: SpaceType::CoSpace);

        $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/booking/{$booking->id}/pay")
            ->assertCreated()
            ->assertJsonPath('data.next', 'pay_at_reception');
    }

    public function test_a_walk_in_still_needs_its_method(): void
    {
        $session = WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => Space::factory()->create(['space_type' => SpaceType::CoSpace])->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'price_points' => 100,
        ]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/member/coworking-sessions/walkin/{$session->id}/pay")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }
}
