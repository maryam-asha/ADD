<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventRegistrationStatus;
use App\Support\Presenters\PaymentStatePresenter;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One payment block, the same shape on everything a member pays for
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 10).
 */
class PaymentStateBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function presenter(): PaymentStatePresenter
    {
        return app(PaymentStatePresenter::class);
    }

    /**
     * The detail keeps paid/unpaid and the payment to follow; the rest of
     * the payment block left it on 2026-10-04
     * (docs/decisions/booking-outcomes-and-extension.md).
     */
    public function test_a_booking_reads_unpaid_then_paid(): void
    {
        $member = $this->member(300);
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            'price_points' => 100,
        ]);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'unpaid')
            ->assertJsonPath('data.total.points', 100)
            ->assertJsonPath('data.awaiting_payment', false)
            ->assertJsonPath('data.payment_id', null);

        $this->postJson('/api/v1/member/payments', ['purpose' => 'booking', 'method' => 'wallet', 'payable_id' => $booking->id])
            ->assertCreated();

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_state', 'paid')
            ->assertJsonPath('data.payment_id', Payment::sole()->id);

        // The list card says paid or unpaid; the payment block is the detail's.
        $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')
            ->assertOk()
            ->assertJsonPath('data.0.payment_state', 'paid');
    }

    public function test_a_card_payment_in_flight_reads_pending(): void
    {
        $member = $this->member();
        $booking = Booking::factory()->create(['user_id' => $member->id, 'payment_state' => PaymentState::Unpaid, 'payment_source' => null, 'price_points' => 100]);
        Payment::factory()->forPayable($booking)->create(['user_id' => $member->id, 'wallet_id' => Wallet::first()->id, 'amount_points' => 100]);

        $block = $this->presenter()->for($booking->refresh(), $member);

        $this->assertSame('pending', $block['state']);
        $this->assertSame(100, $block['amount_points']);
        $this->assertNotNull($block['price']);
    }

    public function test_a_free_event_registration_has_nothing_to_pay(): void
    {
        $member = $this->member();
        $event = Event::create([
            'title' => ['en' => 'Meetup', 'ar' => 'لقاء'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'location' => 'Hall',
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
        ]);
        $attendee = EventAttendee::create([
            'event_id' => $event->id, 'user_id' => $member->id, 'name' => 'M', 'email' => 'm@example.com',
            'status' => EventRegistrationStatus::Approved,
        ]);

        $block = $this->presenter()->for($attendee, $member);

        $this->assertNull($block['state']);
        $this->assertNull($block['amount_points']);
    }

    public function test_a_plan_held_for_cash_reads_pending(): void
    {
        $member = $this->member();
        $plan = Plan::factory()->create(['price_points' => 1200, 'is_active' => true]);
        ['payment' => $hold] = app(PaymentService::class)->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        $block = $this->presenter()->for($hold, $member);

        $this->assertSame('pending', $block['state']);
        $this->assertSame('cash', $block['method']);
        $this->assertSame(1200, $block['amount_points']);
        $this->assertSame($hold->id, $block['payment_id']);
    }

    public function test_an_open_session_has_nothing_to_pay_yet_and_a_closed_one_owes(): void
    {
        $member = $this->member();
        $open = WalkinSession::factory()->create(['user_id' => $member->id, 'checked_out_at' => null]);
        $this->assertNull($this->presenter()->for($open, $member)['state']);

        $closed = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'amount_owed' => '15.00',
            'price_points' => 150,
            'payment_state' => PaymentState::Unpaid,
        ]);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/coworking-sessions/walkin/{$closed->id}/report")
            ->assertOk()
            ->assertJsonPath('data.totals.payment.state', 'unpaid')
            ->assertJsonPath('data.totals.payment.amount_points', 150);
    }
}
