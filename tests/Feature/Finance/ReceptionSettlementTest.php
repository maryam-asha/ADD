<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception settling anything a member owes, with the money actually handed
 * over (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 9): the
 * money becomes points in the member's wallet, what is due is spent, and
 * anything over stays as points.
 */
class ReceptionSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const SYP_RATE = '0.0001';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['*']);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function balanceOf(User $member): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail()
        );
    }

    private function closedUnpaidBooking(User $member, int $points = 100): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => now()->subHour(),
            'amount_owed' => bcdiv((string) $points, '10', 2),
            'price_points' => $points,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
        ]);
    }

    private function settle(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/payments/settle', $body);
    }

    public function test_the_exact_amount_settles_a_booking_through_the_ledger(): void
    {
        $member = $this->member();
        $booking = $this->closedUnpaidBooking($member);

        $this->settle(['purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.points_credited', 100)
            ->assertJsonPath('data.points_charged', 100)
            ->assertJsonPath('data.change_points', 0)
            ->assertJsonPath('data.payment.method', 'cash');

        $booking->refresh();
        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        $this->assertSame(PaymentSource::Cash, $booking->payment_source);
        $this->assertSame(PaymentMethod::Cash, $booking->payment_method);
        $this->assertSame($this->operator->id, $booking->paid_by);

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame($this->operator->id, $payment->received_by);
        $this->assertSame('10.00', (string) $payment->received_amount);

        $credit = WalletTransaction::findOrFail($payment->wallet_transaction_id);
        $this->assertSame(100, (int) $credit->amount);
        $this->assertSame(PaymentMethod::Cash, $credit->payment_method);
        $this->assertSame($this->operator->id, $credit->performed_by_user_id);
        $this->assertSame(-100, (int) WalletTransaction::findOrFail($payment->debit_transaction_id)->amount);
        $this->assertSame(0, $this->balanceOf($member));
    }

    public function test_paying_more_in_syrian_pounds_leaves_the_difference_as_points(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => self::SYP_RATE]);
        $member = $this->member();
        $booking = $this->closedUnpaidBooking($member);

        // 150,000 SYP x 0.0001 = $15 = 150 points, for a 100-point booking.
        $this->settle([
            'purpose' => 'booking',
            'payable_id' => $booking->id,
            'collected_via' => 'cash',
            'amount' => '150000',
            'currency' => 'SYP',
        ])
            ->assertCreated()
            ->assertJsonPath('data.points_credited', 150)
            ->assertJsonPath('data.points_charged', 100)
            ->assertJsonPath('data.change_points', 50);

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(50, $this->balanceOf($member));
        $this->assertSame('SYP', Payment::sole()->received_currency);
    }

    public function test_paying_too_little_is_refused_with_the_shortage(): void
    {
        $member = $this->member();
        $booking = $this->closedUnpaidBooking($member);

        $this->settle(['purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => '7.50'])
            ->assertStatus(422)
            ->assertJsonPath('shortage', '2.50')
            ->assertJsonPath('currency', 'USD');

        $this->assertSame(PaymentState::Unpaid, $booking->refresh()->payment_state);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_a_paid_booking_cannot_be_settled_again(): void
    {
        $booking = $this->closedUnpaidBooking($this->member());
        $booking->forceFill(['payment_state' => PaymentState::Paid])->save();

        $this->settle(['purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash'])
            ->assertStatus(409);
    }

    public function test_a_plan_held_for_cash_is_activated_when_reception_takes_the_money(): void
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id, 'capacity' => 10]);
        $member = $this->member();
        $plan = Plan::factory()->create(['price_points' => 1200, 'duration_days' => 30, 'is_active' => true]);
        ['payment' => $hold] = app(PaymentService::class)->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        $this->settle(['purpose' => 'plan_purchase', 'payment_id' => $hold->id, 'collected_via' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.payment.id', $hold->id)
            ->assertJsonPath('data.points_charged', 1200);

        $membership = Membership::sole();
        $this->assertSame($member->id, $membership->owner_id);
        $hold->refresh();
        $this->assertSame(PaymentStatus::Accepted, $hold->status);
        $this->assertSame($membership->id, $hold->membership_id);
        $this->assertSame(PaymentMethod::Cash, $hold->collected_via);
    }

    public function test_a_plan_whose_branch_is_full_records_nothing(): void
    {
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id, 'capacity' => 1]);
        Membership::factory()->create(['branch_id' => $branch->id]);
        $member = $this->member();
        $plan = Plan::factory()->create(['price_points' => 1200, 'duration_days' => 30, 'is_active' => true]);
        ['payment' => $hold] = app(PaymentService::class)->start(PaymentPurpose::PlanPurchase, $member, 'cash', ['plan' => $plan]);

        $this->settle(['purpose' => 'plan_purchase', 'payment_id' => $hold->id, 'collected_via' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.membership.seat_pool_full', [], 'en'));

        $this->assertSame(PaymentStatus::Pending, $hold->refresh()->status);
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(1, Membership::count());
    }

    public function test_an_event_registration_settled_at_the_desk_can_then_check_in(): void
    {
        $member = $this->member();
        $event = Event::create([
            'title' => ['en' => 'Talk', 'ar' => 'محاضرة'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'location' => 'Hall',
            'is_free' => false,
            'price_points' => 80,
            'status' => 'published',
        ]);
        $attendee = EventAttendee::create([
            'event_id' => $event->id,
            'user_id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'status' => EventRegistrationStatus::Approved,
            'price_points' => 80,
            'payment_state' => EventPaymentState::Unpaid,
            'payment_method' => 'cash',
        ]);

        $this->settle(['purpose' => 'event_registration', 'payable_id' => $attendee->id, 'collected_via' => 'cash'])
            ->assertCreated();

        $this->assertSame(EventPaymentState::Paid, $attendee->refresh()->payment_state);
        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/check-in")
            ->assertOk();
    }

    public function test_the_old_booking_endpoint_answers_as_before_and_now_writes_the_ledger(): void
    {
        $member = $this->member();
        $booking = $this->closedUnpaidBooking($member);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/admin/reception/bookings/{$booking->id}/settle-payment", ['payment_method' => 'cash'])
            ->assertOk()
            ->assertExactJson(['message' => __('api.reception.payment_settled', [], 'en')]);

        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
        $this->assertSame(PaymentMethod::Cash, $booking->payment_method);
        $payment = Payment::sole();
        $this->assertNotNull($payment->wallet_transaction_id);
        $this->assertNotNull($payment->debit_transaction_id);
    }

    public function test_the_old_walk_in_endpoint_answers_as_before_and_now_writes_the_ledger(): void
    {
        $member = $this->member();
        $session = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subHour(),
            'amount_owed' => '15.00',
            'price_points' => 150,
            'payment_state' => PaymentState::Unpaid,
        ]);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/admin/reception/walk-ins/{$session->id}/settle-payment", ['payment_method' => 'cash'])
            ->assertOk()
            ->assertExactJson(['message' => __('api.reception.payment_settled', [], 'en')]);

        $this->assertSame(PaymentState::Paid, $session->refresh()->payment_state);
        $this->assertSame(PaymentMethod::Cash, $session->payment_method);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_reception_top_up_now_leaves_a_payment_row(): void
    {
        $member = $this->member();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '25.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame(PaymentPurpose::WalletTopUp, $payment->purpose);
        $this->assertSame('cash', $payment->method);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(PaymentMethod::Cash, $payment->collected_via);
        $this->assertSame(WalletTransaction::sole()->id, $payment->wallet_transaction_id);
        $this->assertSame(250, $payment->amount_points);
    }

    public function test_admins_can_filter_payments_by_method_and_purpose(): void
    {
        $member = $this->member();
        $this->postJson('/api/v1/admin/reception/wallet-top-ups', ['user_id' => $member->id, 'amount' => '25.00', 'payment_method' => 'cash'])->assertCreated();
        $booking = $this->closedUnpaidBooking($member);
        $this->settle(['purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash'])->assertCreated();

        $this->getJson('/api/v1/admin/payments?purpose=booking')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/payments?method=cash')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/payments?method=paymera')->assertOk()->assertJsonCount(0, 'data');
    }
}
