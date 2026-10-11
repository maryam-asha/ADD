<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState as BookingPaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashRegisterSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['dashboard']);
    }

    public function test_operator_can_open_cash_register_session(): void
    {
        $response = $this->postJson('/api/v1/admin/reception/cash-register-sessions/open', [
            'branch_id' => $this->branch->id,
            'opening_balance' => '150.00',
            'notes' => 'Morning shift',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.opening_balance', '150.00');

        $this->assertDatabaseHas('cash_register_sessions', [
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'status' => 'open',
            'opening_balance' => '150.00',
        ]);
    }

    public function test_operator_cannot_open_duplicate_active_session(): void
    {
        CashRegisterSession::create([
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'opened_at' => now(),
            'opening_balance' => '100.00',
            'status' => CashRegisterSessionStatus::Open,
        ]);

        $response = $this->postJson('/api/v1/admin/reception/cash-register-sessions/open', [
            'branch_id' => $this->branch->id,
            'opening_balance' => '50.00',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('session');
    }

    public function test_operator_can_view_current_session(): void
    {
        $session = CashRegisterSession::create([
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'opened_at' => now(),
            'opening_balance' => '200.00',
            'status' => CashRegisterSessionStatus::Open,
        ]);

        $response = $this->getJson('/api/v1/admin/reception/cash-register-sessions/current');

        $response->assertOk();
        $response->assertJsonPath('data.id', $session->id);
        $response->assertJsonPath('data.opening_balance', '200.00');
    }

    public function test_operator_can_close_session_and_reconcile_cash(): void
    {
        $session = CashRegisterSession::create([
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'opened_at' => now()->subHours(4),
            'opening_balance' => '100.00',
            'status' => CashRegisterSessionStatus::Open,
        ]);

        $wallet = Wallet::create([
            'owner_type' => OwnerType::User,
            'owner_id' => $this->operator->id,
            'currency' => 'USD',
            'balance' => '0.00',
        ]);

        // Add cash transactions conducted by this operator during the shift
        WalletTransaction::create([
            'wallet_id' => $wallet->id,
            'amount' => '50.00',
            'balance_after' => '50.00',
            'currency' => 'USD',
            'source' => WalletTransactionSource::TopUp,
            'category' => WalletTransactionCategory::General,
            'performed_by_user_id' => $this->operator->id,
            'payment_method' => PaymentMethod::Cash->value,
            // The money handed over, which is what the drawer holds — not
            // the points it bought.
            'payment_amount' => '50.00',
            'payment_currency' => 'USD',
            'created_at' => now()->subHours(2),
        ]);

        // 100 opening + 50 cash collected = 150 expected. Operator counts 155 (5 overage)
        $response = $this->postJson("/api/v1/admin/reception/cash-register-sessions/{$session->id}/close", [
            'closing_balance' => '155.00',
            'notes' => 'Shift ended smoothly',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'closed');
        $response->assertJsonPath('data.closing_balance', '155.00');
        $response->assertJsonPath('data.expected_balance', '150.00');
        $response->assertJsonPath('data.difference', '5.00');

        $this->assertDatabaseHas('cash_register_sessions', [
            'id' => $session->id,
            'status' => 'closed',
            'closing_balance' => '155.00',
            'expected_balance' => '150.00',
            'difference' => '5.00',
        ]);
    }

    public function test_operator_can_open_session_without_branch_id_using_default_branch(): void
    {
        $this->branch->update(['is_default' => true]);

        // branch_id is completely omitted from the payload
        $response = $this->postJson('/api/v1/admin/reception/cash-register-sessions/open', [
            'opening_balance' => '200.00',
            'notes' => 'Automatic branch session',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'open');
        $response->assertJsonPath('data.branch_id', $this->branch->id);

        $this->assertDatabaseHas('cash_register_sessions', [
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'status' => 'open',
            'opening_balance' => '200.00',
        ]);
    }

    private function openShift(): CashRegisterSession
    {
        return CashRegisterSession::create([
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'opened_at' => now()->subHour(),
            'opening_balance' => '0.00',
            'status' => CashRegisterSessionStatus::Open,
        ]);
    }

    private function closeFor(CashRegisterSession $session): string
    {
        return $this->postJson("/api/v1/admin/reception/cash-register-sessions/{$session->id}/close", ['closing_balance' => '0.00'])
            ->assertOk()
            ->json('data.expected_balance');
    }

    /**
     * $25 handed over buys 250 points; the drawer holds $25.
     */
    public function test_a_cash_top_up_counts_the_money_not_the_points(): void
    {
        $session = $this->openShift();
        $member = User::factory()->create();
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id, 'points' => 250, 'amount' => '25.00', 'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('25.00', $this->closeFor($session));
    }

    /**
     * Money in another currency is counted at the rate it was taken at.
     */
    public function test_money_in_another_currency_is_counted_at_its_recorded_rate(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        $session = $this->openShift();
        $member = User::factory()->create();
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id, 'points' => 150, 'amount' => '150000', 'currency' => 'SYP', 'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('15.00', $this->closeFor($session));
    }

    public function test_cash_taken_to_settle_a_booking_is_counted(): void
    {
        $session = $this->openShift();
        $member = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subMinutes(10),
            'amount_owed' => '10.00',
            'price_points' => 100,
            'payment_state' => BookingPaymentState::Unpaid,
        ]);

        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash', 'amount' => '12.00',
        ])->assertCreated();

        $this->assertSame('12.00', $this->closeFor($session));
    }

    public function test_reception_takes_cash_only(): void
    {
        $session = $this->openShift();
        $member = User::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'checked_out_at' => now()->subMinutes(10),
            'amount_owed' => '10.00',
            'price_points' => 100,
            'payment_state' => BookingPaymentState::Unpaid,
        ]);

        foreach (['sham', 'mtn', 'syriatel', 'paymera'] as $method) {
            $this->postJson('/api/v1/admin/reception/payments/settle', [
                'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => $method,
            ])->assertUnprocessable()->assertJsonValidationErrors('collected_via');

            $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
                'user_id' => $member->id, 'points' => 100, 'amount' => '10.00', 'payment_method' => $method,
            ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        }

        $this->assertSame(BookingPaymentState::Unpaid, $booking->fresh()->payment_state);
        $this->assertSame('0.00', $this->closeFor($session));
    }
}
