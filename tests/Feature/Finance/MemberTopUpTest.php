<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST member/wallet/top-ups — a member adds points to their own wallet, by a
 * prepaid method only. Cash for points is reception's
 * (admin/reception/wallet-top-ups), never the app's.
 *
 * Rates chosen so the arithmetic is plain: 1 point = 0.10 USD and
 * 1 USD = 10,000 SYP, so 10 points cost 10,000.00 SYP.
 */
class MemberTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '14740460',
            'paymera.currency' => 'SYP',
            'payments.min_top_up_points' => 10,
        ]);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/ar', 'paymentId' => 'pay-1'],
            ]),
        ]);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);
    }

    private function topUp(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/wallet/top-ups', $body);
    }

    public function test_a_top_up_opens_a_card_payment(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-1/ar')
            ->assertJsonPath('data.payment.status', 'pending')
            // Paymera's own id for it, beside ours.
            ->assertJsonPath('data.payment.gateway_payment_id', 'pay-1');
    }

    /** The method is always named, so a second gateway needs no app change. */
    public function test_the_method_is_required(): void
    {
        $this->topUp(['amount' => '10000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }

    public function test_cash_is_reception_only(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }

    public function test_points_cannot_buy_points(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    /**
     * A gateway added in config tops up through itself: the payment is
     * opened by, and recorded under, the method the member chose.
     */
    public function test_a_second_gateway_is_used_when_chosen(): void
    {
        config(['payments.methods.other_card' => ['driver' => PaymeraGateway::class, 'enabled' => true]]);

        $this->topUp(['amount' => '10000', 'payment_method' => 'other_card'])
            ->assertCreated()
            ->assertJsonPath('data.payment.method', 'other_card')
            ->assertJsonPath('data.next', 'redirect');

        $this->assertSame('other_card', Payment::sole()->method);
    }

    public function test_a_gateway_that_is_switched_off_is_refused(): void
    {
        config(['payments.methods.paymera.enabled' => false]);

        $this->topUp(['amount' => '10000', 'payment_method' => 'paymera'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_minimum_is_named_in_money(): void
    {
        $this->topUp(['amount' => '9900', 'payment_method' => 'paymera'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 10,000 SYP (10 points).');
    }

    public function test_an_amount_is_required(): void
    {
        $this->topUp(['payment_method' => 'paymera'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_payment_methods_for_a_top_up_are_prepaid_only(): void
    {
        $keys = array_column($this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')->json('data.methods'), 'key');

        $this->assertSame(['paymera'], $keys);
    }
}
