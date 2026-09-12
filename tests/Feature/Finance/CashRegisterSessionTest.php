<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\CashRegisterSession;
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
}
