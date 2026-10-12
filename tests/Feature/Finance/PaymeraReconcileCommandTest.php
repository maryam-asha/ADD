<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `paymera:reconcile` — the backstop that settles payments no callback or
 * trigger ever resolved, and writes off the ones that never will.
 */
class PaymeraReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => self::TERMINAL,
            // Charging in the base currency keeps these tests about
            // reconciliation rather than about FX: a non-base currency needs
            // a live exchange rate, and its absence would fail the credit for
            // an unrelated reason.
            'paymera.currency' => 'USD',
            'paymera.pending_expiry_minutes' => 60,
        ]);
    }

    private function paymentFor(User $member, array $attributes = []): Payment
    {
        $wallet = Wallet::firstOrCreate(
            ['owner_type' => OwnerType::User, 'owner_id' => $member->id]
        );

        return Payment::factory()->create(array_merge([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'charged_amount' => 1000,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
            'status' => PaymentStatus::Pending,
        ], $attributes));
    }

    private function fakeStatus(string $status): void
    {
        Http::fake([
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => $status,
                    'amount' => 1000,
                    'terminalId' => self::TERMINAL,
                    'rrn' => '000009876543',
                ],
            ]),
        ]);
    }

    public function test_it_settles_a_pending_payment_that_has_since_been_accepted(): void
    {
        $member = User::factory()->create();
        $payment = $this->paymentFor($member);

        $this->fakeStatus('A');

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);

        // The member's money reaches their wallet even though they never
        // came back to the callback — the whole point of this command.
        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(100, app(WalletService::class)->generalBalance($wallet));
    }

    public function test_a_payment_still_pending_past_its_expiry_is_written_off(): void
    {
        $member = User::factory()->create();
        $payment = $this->paymentFor($member);

        // Older than pending_expiry_minutes.
        $payment->forceFill(['created_at' => now()->subMinutes(120)])->save();

        $this->fakeStatus('P');

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $payment->refresh();
        $this->assertSame(PaymentStatus::Expired, $payment->status);
        $this->assertStringContainsString('still pending', $payment->review_reason);

        // Nothing credited for an expired payment.
        $wallet = Wallet::where('owner_id', $member->id)->first();
        $this->assertSame(0, app(WalletService::class)->generalBalance($wallet));
    }

    /**
     * An old payment gets ONE more check before being written off — if it
     * settled in the meantime, that result wins over the expiry.
     */
    public function test_an_old_payment_that_has_actually_been_accepted_is_credited_not_expired(): void
    {
        $member = User::factory()->create();
        $payment = $this->paymentFor($member);
        $payment->forceFill(['created_at' => now()->subMinutes(120)])->save();

        $this->fakeStatus('A');

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Accepted, $payment->refresh()->status);
    }

    public function test_it_leaves_a_recent_pending_payment_alone(): void
    {
        $member = User::factory()->create();
        $payment = $this->paymentFor($member);

        $this->fakeStatus('P');

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_it_ignores_payments_that_never_got_a_gateway_id(): void
    {
        $member = User::factory()->create();
        $this->paymentFor($member, ['payment_id' => null]);

        Http::fake();

        $this->artisan('paymera:reconcile')->assertSuccessful();

        // Nothing to ask about, so the gateway is never called.
        Http::assertNothingSent();
    }

    public function test_it_does_not_touch_already_settled_payments(): void
    {
        $member = User::factory()->create();
        $this->paymentFor($member, ['status' => PaymentStatus::Accepted]);

        Http::fake();

        $this->artisan('paymera:reconcile')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * One unreachable payment must not abort the sweep — the rest still
     * need checking.
     */
    public function test_a_gateway_failure_on_one_payment_does_not_stop_the_others(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $failing = $this->paymentFor($first, ['payment_id' => 'will-fail']);
        $succeeding = $this->paymentFor($second, ['payment_id' => 'will-work']);

        Http::fake([
            '*/api/get-payment-status/will-fail' => Http::response([], 500),
            '*/api/get-payment-status/will-work' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'amount' => 1000,
                    'terminalId' => self::TERMINAL,
                ],
            ]),
        ]);

        $this->artisan('paymera:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $failing->refresh()->status);
        $this->assertSame(PaymentStatus::Accepted, $succeeding->refresh()->status);
    }
}
