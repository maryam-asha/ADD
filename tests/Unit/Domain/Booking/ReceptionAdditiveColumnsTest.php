<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionAdditiveColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_space_can_carry_a_cancellation_window_override(): void
    {
        $space = Space::factory()->room()->create(['cancellation_window_minutes' => 120]);

        $this->assertSame(120, $space->fresh()->cancellation_window_minutes);
    }

    public function test_a_space_without_an_override_has_a_null_cancellation_window(): void
    {
        $space = Space::factory()->room()->create();

        $this->assertNull($space->fresh()->cancellation_window_minutes);
    }

    public function test_a_wallet_transaction_can_record_the_operator_and_payment_method(): void
    {
        $wallet = Wallet::factory()->create();
        $operator = User::factory()->create();
        $transaction = (new WalletService)->creditGeneral($wallet, '10.00', WalletTransactionSource::TopUp);

        $transaction->forceFill([
            'performed_by_user_id' => $operator->id,
            'payment_method' => PaymentMethod::Cash,
        ])->save();

        $transaction->refresh();
        $this->assertTrue($transaction->performedBy->is($operator));
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);
    }

    public function test_a_wallet_transaction_without_an_operator_has_null_performed_by(): void
    {
        $wallet = Wallet::factory()->create();
        $transaction = (new WalletService)->creditGeneral($wallet, '10.00', WalletTransactionSource::TopUp);

        $this->assertNull($transaction->fresh()->performed_by_user_id);
        $this->assertNull($transaction->fresh()->payment_method);
    }
}
