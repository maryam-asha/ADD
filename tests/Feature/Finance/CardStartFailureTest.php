<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Support\Enums\EventPaymentState;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A card payment that cannot start never leaves a payment pending
 * (final review I2, and T2's "no orphan row"): nothing due is refused
 * before any row is written; a failure once the row exists — pricing
 * included — marks it Failed; and a record already made (a booking, a
 * registration) stands, answered 201 with `payment_error`.
 */
class CardStartFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-9/en', 'paymentId' => 'pay-9'],
            ]),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function space(int $rate): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => $rate, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function bookByCard(Space $space)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17T12:00:00+03:00',
            'end_at' => '2026-08-17T13:00:00+03:00',
            'payment_method' => 'paymera',
        ]);
    }

    /** A charge currency with no exchange rate: the card cannot be priced. */
    private function withoutARate(): void
    {
        config(['paymera.currency' => 'SYP']);
    }

    private function assertNoPaymentIsPending(): void
    {
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Pending)->count());
    }

    /** Probe F. */
    public function test_a_free_booking_made_with_card_stands_with_nothing_to_pay_and_no_payment(): void
    {
        $this->member();

        $this->bookByCard($this->space(0))
            ->assertCreated()
            ->assertJsonPath('payment', null)
            ->assertJsonPath('redirect_url', null)
            ->assertJsonPath('payment_error', __('api.payment.nothing_due', [], 'en'));

        $this->assertSame(1, Booking::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_pay_now_for_a_booking_that_owes_nothing_is_refused_before_any_row_is_written(): void
    {
        $member = $this->member();
        $booking = Booking::factory()->create([
            'space_id' => $this->space(0)->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 0,
        ]);

        foreach (['paymera', 'wallet'] as $method) {
            $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', [
                'purpose' => 'booking', 'method' => $method, 'payable_id' => $booking->id,
            ])
                ->assertStatus(422)
                ->assertJsonPath('message', __('api.payment.nothing_due', [], 'en'));
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_a_booking_made_with_card_without_an_exchange_rate_stands_and_leaves_no_pending_payment(): void
    {
        $this->member();
        $this->withoutARate();

        $this->bookByCard($this->space(100))
            ->assertCreated()
            ->assertJsonPath('payment', null)
            ->assertJsonPath('payment_error', __('api.payment.gateway_unavailable', [], 'en'));

        $this->assertSame(PaymentState::Unpaid, Booking::sole()->payment_state);
        $this->assertNoPaymentIsPending();
        Http::assertNothingSent();
    }

    public function test_pay_now_by_card_without_an_exchange_rate_is_a_503_and_leaves_no_pending_payment(): void
    {
        $member = $this->member();
        $booking = Booking::factory()->create([
            'space_id' => $this->space(100)->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);
        $this->withoutARate();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', [
            'purpose' => 'booking', 'method' => 'paymera', 'payable_id' => $booking->id,
        ])
            ->assertStatus(503)
            ->assertJsonPath('message', __('api.payment.gateway_unavailable', [], 'en'));

        $this->assertNoPaymentIsPending();
        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
    }

    public function test_an_event_registration_by_card_without_an_exchange_rate_stands_and_leaves_no_pending_payment(): void
    {
        $this->member();
        $this->withoutARate();
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

        $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$event->id}/register", ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.payment_error', __('api.payment.gateway_unavailable', [], 'en'));

        $this->assertSame(EventPaymentState::Unpaid, EventAttendee::sole()->payment_state);
        $this->assertNoPaymentIsPending();
    }
}
