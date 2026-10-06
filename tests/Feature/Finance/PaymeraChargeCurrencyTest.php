<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Charging in a currency that is not the base currency — the real
 * deployment shape, since the bank settles in SYP while the ledger's base
 * is USD.
 *
 * Two things are pinned here:
 *
 *  1. The ledger records what the member actually paid, in the currency
 *     they paid it in, alongside the rate used. An earlier version wrote
 *     the USD value next to a "SYP" label, which made every non-USD card
 *     credit misreport the amount.
 *  2. A missing exchange rate is an operational misconfiguration, not a
 *     crash: the member gets a translated 503, not a raw 500.
 */
class PaymeraChargeCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '14740460';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => self::TERMINAL,
            // The production shape: charge in SYP against a USD base.
            'paymera.currency' => 'SYP',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'ar',
        ]);
    }

    /**
     * 1 USD ≈ 14,700 SYP, the factory's real-world rate.
     */
    private function syrianRate(): ExchangeRate
    {
        return ExchangeRate::factory()->create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0000680272',
        ]);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);

        return $member;
    }

    private function fakeGateway(int $amountMinor): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'url' => 'https://egate-t.paymera.test/start/pay-1/ar',
                    'paymentId' => 'pay-1',
                ],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'amount' => $amountMinor,
                    'terminalId' => self::TERMINAL,
                    'rrn' => '000053127772',
                ],
            ]),
        ]);
    }

    /**
     * The pairing that matters: `payment_amount` is in `payment_currency`,
     * and the rate that connects it back to USD is stored alongside.
     */
    public function test_the_ledger_records_the_syrian_amount_not_the_usd_value(): void
    {
        $rate = $this->syrianRate();
        $member = $this->member();
        $wallet = Wallet::where('owner_id', $member->id)->first();

        // 147,000,000 minor units = 1,470,000.00 SYP ≈ 100 USD = 1000 points.
        $amountMinor = 147_000_000;
        $this->fakeGateway($amountMinor);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => $amountMinor,
            'currency' => 'SYP',
            'currency_exponent' => 2,
            'points_to_credit' => 1000,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $transaction = $payment->refresh()->walletTransaction;

        $this->assertNotNull($transaction, 'The payment should have been credited.');
        $this->assertSame('SYP', $transaction->payment_currency);

        // The SYP amount actually charged — not its ~100.00 USD value.
        $this->assertSame('1470000.00', (string) $transaction->payment_amount);
        $this->assertNotSame('100.00', (string) $transaction->payment_amount);

        // And the rate that makes the USD value re-derivable later.
        $this->assertSame(
            (string) $rate->rate_to_base,
            (string) $transaction->exchange_rate_to_base
        );

        // The points credited are unaffected by any of this.
        $this->assertSame(1000, app(WalletService::class)->generalBalance($wallet));
    }

    /**
     * When the charge is already in the base currency there is no
     * conversion, so there is no rate to record.
     */
    public function test_a_base_currency_charge_records_no_exchange_rate(): void
    {
        config(['paymera.currency' => 'USD']);

        $member = $this->member();
        $wallet = Wallet::where('owner_id', $member->id)->first();

        $this->fakeGateway(1000);

        $payment = Payment::factory()->create([
            'user_id' => $member->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => 1000,
            'currency' => 'USD',
            'currency_exponent' => 2,
            'points_to_credit' => 100,
            'terminal_id' => self::TERMINAL,
        ]);

        app(SyncPaymentStatus::class)->handle($payment);

        $transaction = $payment->refresh()->walletTransaction;

        $this->assertSame('USD', $transaction->payment_currency);
        $this->assertSame('10.00', (string) $transaction->payment_amount);
        $this->assertNull($transaction->exchange_rate_to_base);
    }

    /**
     * The blocker this guards against: with SYP configured and no rate
     * entered, every payment used to escape as an uncaught
     * InvalidArgumentException — a raw 500 with an English stack message.
     */
    public function test_a_missing_exchange_rate_returns_a_translated_503_not_a_500(): void
    {
        $this->member();

        // Deliberately no ExchangeRate row.
        Http::fake();

        $response = $this->postJson('/api/v1/member/wallet/top-ups', [
            'amount' => '1470000',
        ]);

        $response->assertStatus(503)
            ->assertJsonStructure(['message']);

        // Never reached the gateway — the failure is entirely ours.
        Http::assertNothingSent();

        // And no half-open payment row was left behind.
        $this->assertSame(0, Payment::count());
    }

    public function test_the_missing_rate_message_is_localized(): void
    {
        $this->member();
        Http::fake();

        $response = $this->postJson('/api/v1/member/wallet/top-ups', [
            'amount' => '1470000',
        ], ['lang' => 'ar']);

        $response->assertStatus(503);
        $this->assertSame(__('api.payment.gateway_unavailable', [], 'ar'), $response->json('message'));
    }

    /**
     * A plan purchase prices from `price_points` through the same
     * conversion, so it fails the same way rather than charging a wrong
     * amount.
     */
    public function test_a_plan_purchase_also_refuses_without_a_rate(): void
    {
        $this->member();
        $plan = Plan::factory()->create([
            'price_points' => 1000,
            'is_active' => true,
            'is_public' => true,
        ]);

        Http::fake();

        $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'payment_method' => 'paymera',
        ])->assertStatus(503);

        Http::assertNothingSent();
    }

    /**
     * With a rate present, the charge is derived from the plan's point
     * price and sent to Paymera as a bare integer in SYP minor units.
     */
    public function test_a_plan_is_charged_in_syrian_minor_units(): void
    {
        $this->syrianRate();
        $this->member();

        $plan = Plan::factory()->create([
            'price_points' => 1000,   // 1000 × 0.10 USD = 100.00 USD
            'is_active' => true,
            'is_public' => true,
        ]);

        // 1000 points = 100.00 USD. The stored rate 0.0000680272 is 1/14700
        // truncated to ten places, so the exact conversion is
        // 100 / 0.0000680272 = 1,470,000.2352 SYP — not a round 1,470,000.
        // pointsToMinor() ceils — and the pound has no piasters in use, so to
        // a whole pound: 1,470,001 SYP, 147,000,100 minor units. The member
        // pays under one pound more rather than receiving a fraction of a
        // point's worth for free. That rounding direction is the deliberate
        // house-favouring rule, so it is pinned here exactly.
        $expectedMinor = 147_000_100;

        $this->fakeGateway($expectedMinor);

        $this->postJson('/api/v1/member/memberships', [
            'plan_id' => $plan->id,
            'payment_method' => 'paymera',
        ])->assertCreated();

        $payment = Payment::first();
        $this->assertSame('SYP', $payment->currency);
        $this->assertSame(1000, $payment->points_to_credit);
        $this->assertSame($expectedMinor, $payment->amount_minor);

        // Never rounds DOWN — that would hand out value for free.
        $this->assertGreaterThanOrEqual(147_000_000, $payment->amount_minor);

        Http::assertSent(function ($request) use ($expectedMinor) {
            if (! str_contains($request->url(), 'create-payment')) {
                return false;
            }

            // A bare integer with no decimal point or separators, as the
            // gateway requires.
            return is_int($request['amount'])
                && $request['amount'] === $expectedMinor
                && $request['terminalId'] === self::TERMINAL;
        });
    }
}
